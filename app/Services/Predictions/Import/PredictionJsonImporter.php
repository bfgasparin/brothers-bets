<?php

namespace App\Services\Predictions\Import;

use App\Enums\OrderingScope;
use App\Models\Entry;
use App\Models\Fixture;
use App\Models\GroupPrediction;
use App\Models\KnockoutPrediction;
use App\Models\Pool;
use App\Models\Team;
use App\Models\Tournament;
use App\Services\Predictions\BracketResolver;
use App\Services\Predictions\DefaultTieOrdering;
use App\Services\Predictions\OfficialBracketProjector;
use App\Services\Predictions\PredictionImporter;
use App\Services\Scoring\LeaderboardNotifier;
use App\Services\Scoring\RankSnapshotter;
use App\Services\Scoring\ScoreEngine;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

/**
 * Backfills a user's predictions from a pasted JSON blob, for the admin tool that fills in players who
 * couldn't get into the app to enter their predictions before the lock, leaving their entries empty.
 *
 * The JSON is the same source the normal prediction flow takes, from a different channel. Group
 * scorelines apply to either pool type. The knockout differs by strategy: an UPFRONT pool derives the
 * whole bracket from the group scores ({@see BracketResolver}), so the JSON's knockout match-ups only
 * validate against the derivation; a PHASED pool predicts the OFFICIAL match-ups directly
 * ({@see OfficialBracketProjector} fills them), with no cascade or thirds.
 *
 * Three steps: {@see parse()} maps the blob to fixtures/teams; {@see preview()} builds the bracket in
 * a rolled-back sandbox and returns a review payload (with discrepancy flags) WITHOUT storing
 * anything; {@see commit()} writes the admin-reviewed values and re-scores the one pool. Re-scoring
 * deliberately omits {@see LeaderboardNotifier} — a silent admin backfill must not email the pool.
 */
class PredictionJsonImporter
{
    public function __construct(
        private readonly BracketResolver $resolver = new BracketResolver,
        private readonly DefaultTieOrdering $tieOrdering = new DefaultTieOrdering,
        private readonly ScoreEngine $engine = new ScoreEngine,
        private readonly RankSnapshotter $snapshotter = new RankSnapshotter,
    ) {}

    /**
     * Map a decoded JSON blob to the pool's tournament: resolve every match to a fixture and team
     * ids, the third-place classification to team ids, and collect the codes/numbers that matched
     * nothing for the review banner.
     *
     * @param  array<string, mixed>  $json
     */
    public function parse(Pool $pool, array $json): ParsedImport
    {
        $rawMatches = is_array($json['matches'] ?? null) ? $json['matches'] : [];
        $rawThirds = is_array($json['third_places_classification'] ?? null) ? $json['third_places_classification'] : [];
        $rawStandings = is_array($json['group_standings'] ?? null) ? $json['group_standings'] : [];

        $fixtures = $pool->tournament->fixtures()->with('phase')->get()->keyBy('match_number');
        $teams = $this->teamsByCode($rawMatches, $rawThirds, $rawStandings);

        $unknownCodes = [];
        $unknownNumbers = [];

        $matches = [];
        foreach ($rawMatches as $raw) {
            if (! is_array($raw) || ! isset($raw['match_number'])) {
                continue;
            }

            $number = (int) $raw['match_number'];
            $fixture = $fixtures->get($number);

            if ($fixture === null) {
                $unknownNumbers[] = $number;
            }

            $homeCode = $this->code($raw['home_team'] ?? null);
            $awayCode = $this->code($raw['away_team'] ?? null);
            $advancesCode = $this->code($raw['advances'] ?? null);

            foreach ([$homeCode, $awayCode, $advancesCode] as $code) {
                if ($code !== null && ! $teams->has($code)) {
                    $unknownCodes[] = $code;
                }
            }

            $matches[] = new ParsedMatch(
                matchNumber: $number,
                fixtureId: $fixture?->id,
                isKnockout: (bool) $fixture?->isKnockout(),
                homeCode: $homeCode,
                awayCode: $awayCode,
                homeTeamId: $teams->get($homeCode)?->id,
                awayTeamId: $teams->get($awayCode)?->id,
                homeGoals: $this->intOrNull($raw['home_goals'] ?? null),
                awayGoals: $this->intOrNull($raw['away_goals'] ?? null),
                advancesCode: $advancesCode,
                advancesTeamId: $teams->get($advancesCode)?->id,
            );
        }

        $thirdsIds = [];
        foreach ($rawThirds as $raw) {
            $code = $this->code(is_array($raw) ? ($raw['team'] ?? null) : $raw);

            if ($code === null) {
                continue;
            }

            if ($teams->has($code)) {
                $thirdsIds[] = $teams->get($code)->id;
            } else {
                $unknownCodes[] = $code;
            }
        }

        // The optional "group_standings" gives the user's stated finishing order per group, used only
        // to break a genuine within-group score-tie the derivation can't ({@see overrideGroupOrderings}).
        $groupStandings = [];
        foreach ($rawStandings as $raw) {
            if (! is_array($raw)) {
                continue;
            }

            $label = $this->code($raw['group'] ?? null);
            $codes = is_array($raw['standings'] ?? null) ? $raw['standings'] : [];

            if ($label === null || $codes === []) {
                continue;
            }

            $ids = [];
            foreach ($codes as $rawCode) {
                $code = $this->code($rawCode);

                if ($code === null) {
                    continue;
                }

                if ($teams->has($code)) {
                    $ids[] = $teams->get($code)->id;
                } else {
                    $unknownCodes[] = $code;
                }
            }

            if ($ids !== []) {
                $groupStandings[$label] = array_values(array_unique($ids));
            }
        }

        return new ParsedImport(
            matches: $matches,
            thirdsTeamIds: array_values(array_unique($thirdsIds)),
            unknownTeamCodes: array_values(array_unique($unknownCodes)),
            unknownMatchNumbers: array_values(array_unique($unknownNumbers)),
            groupStandings: $groupStandings,
        );
    }

    /**
     * Whether the entry already holds the user's own predictions. A bare knockout placeholder row
     * (derived participants, no score/advancing pick) does not count — only a real pick.
     */
    public function hasExistingPredictions(Entry $entry): bool
    {
        return $entry->groupPredictions()->exists()
            || $entry->knockoutPredictions()
                ->where(fn ($query) => $query->whereNotNull('home_goals')->orWhereNotNull('advancing_team_id'))
                ->exists();
    }

    /**
     * Build the review payload by applying the import exactly as a commit would (group scores plus
     * the strategy's knockout write) inside a transaction that is rolled back, so NOTHING is
     * persisted. The returned array carries every match with its resolved participants and
     * discrepancy flags.
     *
     * @return array<string, mixed>
     */
    public function preview(Entry $entry, ParsedImport $parsed, bool $literal = false): array
    {
        DB::beginTransaction();

        try {
            $alreadyPopulated = $this->hasExistingPredictions($entry);

            // Authored mode (upfront only) stores the JSON's own knockout participants verbatim; the
            // derived-bracket rows would discard them. Phased pools have no derivation to bypass.
            $literal = $literal && $entry->pool->predictsKnockoutBracket();
            $knockoutRows = $literal ? $parsed->literalKnockoutRows() : $parsed->knockoutRows();

            $this->applyToSandbox($entry, $parsed->groupRows(), $knockoutRows, $parsed->thirdsTeamIds, $parsed->groupStandings, $literal);

            return $this->buildPreviewPayload($entry, $parsed, $alreadyPopulated, $literal);
        } finally {
            // Always discard the sandbox: the preview must store nothing. Nested persist()
            // transactions are savepoints, so this rollback unwinds them too.
            DB::rollBack();
        }
    }

    /**
     * Commit the admin-reviewed values onto the entry, then re-score and re-rank the one pool so
     * its boards reflect the backfilled entry. No leaderboard emails are sent.
     */
    public function commit(Entry $entry, CorrectedImport $corrected): void
    {
        // Authored mode applies only to upfront pools (a phased pool has no derivation to bypass).
        $literal = $corrected->literal && $entry->pool->predictsKnockoutBracket();

        DB::transaction(function () use ($entry, $corrected, $literal): void {
            $this->applyToSandbox($entry, $corrected->groupRows, $corrected->knockoutRows, $corrected->thirdsTeamIds, $corrected->groupStandings, $literal);

            // Mark (authored mode) or clear (derive mode) the bracket so the prediction page never
            // re-derives an as-authored entry; a later derive-mode commit restores normal behaviour.
            $entry->authored_bracket_at = $literal ? now() : null;
            $entry->save();
        });

        // Kept outside the write transaction, mirroring ApproveScoreBatch: each manages its own.
        $this->engine->recompute($entry->pool);
        $this->snapshotter->snapshot($entry->pool);
    }

    /**
     * The shared write path used by both the rolled-back preview and the real commit: replace the
     * group scores, then stamp the knockout predictions. Upfront pools derive the whole bracket from
     * the group scores (tie ordering + cascade); phased pools predict the official match-ups directly.
     *
     * @param  list<array{fixture_id: int, home_goals: int, away_goals: int}>  $groupRows
     * @param  list<array{fixture_id: int, home_goals: int|null, away_goals: int|null, advancing_pick: int|null, predicted_home_team_id?: int|null, predicted_away_team_id?: int|null}>  $knockoutRows
     * @param  list<int>  $thirdsTeamIds
     * @param  array<string, list<int>>  $groupStandings  uppercased group label => stated finishing order
     * @param  bool  $literal  upfront only — store the JSON's authored knockout bracket verbatim instead
     *                         of deriving it from the group scores
     */
    private function applyToSandbox(Entry $entry, array $groupRows, array $knockoutRows, array $thirdsTeamIds, array $groupStandings = [], bool $literal = false): void
    {
        $this->writeGroupPredictions($entry, $groupRows);

        if (! $entry->pool->predictsKnockoutBracket()) {
            // Phased: the bracket is the official one (projected from real results); predict the real
            // match-ups directly. No tie ordering, no third-place cut, no cascade.
            $this->writePhasedKnockoutPredictions($entry, $knockoutRows);

            return;
        }

        if ($literal) {
            // Authored mode: the JSON author placed the knockout participants themselves (their group
            // classification didn't follow FIFA rules). Store that bracket verbatim — no tie ordering,
            // no third-place cut, no cascade — so the player is scored on exactly what they submitted.
            $this->writeLiteralUpfrontKnockoutPredictions($entry, $knockoutRows);

            return;
        }

        // Upfront: no human to break ties — take the deterministic defaults, then honour the user's
        // stated within-group and third-place orderings where they resolve a tie (otherwise stay default).
        $this->tieOrdering->applyToEntry($entry);
        $this->overrideThirdsOrdering($entry, $thirdsTeamIds);
        $this->overrideGroupOrderings($entry, $groupStandings);

        // Derive the Round-of-32 participants from the group scores.
        $this->resolver->persist($entry);

        // Stamp each knockout score/advancing and re-cascade, repeatedly: a round's advancing pick
        // can only be applied once its participants are resolved, which needs the previous round's
        // picks already cascaded. The bracket is five levels deep, so a fixed point is reached well
        // within six passes (the same shape as the test helper that fills a bracket home-team-wins).
        if ($knockoutRows !== []) {
            for ($pass = 0; $pass < 6; $pass++) {
                $this->writeUpfrontKnockoutPredictions($entry, $knockoutRows);
                $this->resolver->persist($entry);
            }
        }
    }

    /**
     * @param  list<array{fixture_id: int, home_goals: int, away_goals: int}>  $rows
     */
    private function writeGroupPredictions(Entry $entry, array $rows): void
    {
        $entry->groupPredictions()->delete();
        $entry->groupOrderings()->delete();

        foreach ($rows as $row) {
            GroupPrediction::create([
                'entry_id' => $entry->id,
                'fixture_id' => $row['fixture_id'],
                'home_goals' => $row['home_goals'],
                'away_goals' => $row['away_goals'],
            ]);
        }
    }

    /**
     * Upfront: stamp each knockout row's score + advancing onto the already-derived bracket. The
     * advancing team is derived from the score against the resolved slot ({@see persist()} fills
     * `predicted_home/away`), with the pick honoured only on a draw — identical to the player save.
     *
     * @param  list<array{fixture_id: int, home_goals: int|null, away_goals: int|null, advancing_pick: int|null}>  $rows
     */
    private function writeUpfrontKnockoutPredictions(Entry $entry, array $rows): void
    {
        if ($rows === []) {
            return;
        }

        $slots = $entry->knockoutPredictions()->get()->keyBy('fixture_id');

        foreach ($rows as $row) {
            $slot = $slots->get($row['fixture_id']);

            KnockoutPrediction::updateOrCreate(
                ['entry_id' => $entry->id, 'fixture_id' => $row['fixture_id']],
                [
                    'home_goals' => $row['home_goals'],
                    'away_goals' => $row['away_goals'],
                    'advancing_team_id' => $this->advancingFor(
                        $row['home_goals'],
                        $row['away_goals'],
                        $slot?->predicted_home_team_id,
                        $slot?->predicted_away_team_id,
                        $row['advancing_pick'],
                    ),
                ],
            );
        }
    }

    /**
     * Authored mode (upfront): store the JSON's own knockout participants verbatim. The bracket is NOT
     * derived from the group scores, so we write `predicted_home/away` straight from what the author
     * typed, and derive the advancing team from the score against THOSE teams ({@see advancingFor} —
     * the higher-scoring side decisively, the "advances" pick only on a draw). Existing knockout rows
     * are cleared first so a re-import (overwrite) leaves exactly the authored bracket, with no stale
     * derived slots for fixtures the blob omitted.
     *
     * @param  list<array{fixture_id: int, predicted_home_team_id: int|null, predicted_away_team_id: int|null, home_goals: int|null, away_goals: int|null, advancing_pick: int|null}>  $rows
     */
    private function writeLiteralUpfrontKnockoutPredictions(Entry $entry, array $rows): void
    {
        $entry->knockoutPredictions()->delete();

        foreach ($rows as $row) {
            KnockoutPrediction::create([
                'entry_id' => $entry->id,
                'fixture_id' => $row['fixture_id'],
                'predicted_home_team_id' => $row['predicted_home_team_id'] ?? null,
                'predicted_away_team_id' => $row['predicted_away_team_id'] ?? null,
                'home_goals' => $row['home_goals'],
                'away_goals' => $row['away_goals'],
                'advancing_team_id' => $this->advancingFor(
                    $row['home_goals'],
                    $row['away_goals'],
                    $row['predicted_home_team_id'] ?? null,
                    $row['predicted_away_team_id'] ?? null,
                    $row['advancing_pick'] ?? null,
                ),
            ]);
        }
    }

    /**
     * Phased: predict the OFFICIAL match-ups directly. The participants are the official ones already
     * on the knockout fixtures (filled by {@see OfficialBracketProjector} as
     * rounds complete), so the prediction records them and derives the advancing team from the score
     * against them — no cascade. Mirrors the phased branch of {@see PredictionImporter}.
     *
     * @param  list<array{fixture_id: int, home_goals: int|null, away_goals: int|null, advancing_pick: int|null}>  $rows
     */
    private function writePhasedKnockoutPredictions(Entry $entry, array $rows): void
    {
        if ($rows === []) {
            return;
        }

        $fixtures = $entry->pool->tournament->knockoutFixtures()->get()->keyBy('id');

        foreach ($rows as $row) {
            $fixture = $fixtures->get($row['fixture_id']);

            KnockoutPrediction::updateOrCreate(
                ['entry_id' => $entry->id, 'fixture_id' => $row['fixture_id']],
                [
                    'predicted_home_team_id' => $fixture?->home_team_id,
                    'predicted_away_team_id' => $fixture?->away_team_id,
                    'home_goals' => $row['home_goals'],
                    'away_goals' => $row['away_goals'],
                    'advancing_team_id' => $this->advancingFor(
                        $row['home_goals'],
                        $row['away_goals'],
                        $fixture?->home_team_id,
                        $fixture?->away_team_id,
                        $row['advancing_pick'],
                    ),
                ],
            );
        }
    }

    /**
     * Replace the straddling thirds ordering with the user's pasted order, but only when it is a
     * full permutation of the cluster the default ordering identified — otherwise keep the default.
     *
     * @param  list<int>  $thirdsTeamIds
     */
    private function overrideThirdsOrdering(Entry $entry, array $thirdsTeamIds): void
    {
        if ($thirdsTeamIds === []) {
            return;
        }

        $row = $entry->groupOrderings()->where('scope', OrderingScope::Thirds)->first();

        if ($row === null) {
            return;
        }

        $tied = array_map('intval', $row->tied_team_ids);
        $ordered = array_values(array_filter($thirdsTeamIds, fn (int $id): bool => in_array($id, $tied, true)));

        if (count($ordered) === count($tied) && array_diff($tied, $ordered) === []) {
            $row->update(['ordered_team_ids' => $ordered]);
        }
    }

    /**
     * Replace each within-group default ordering with the order the user stated in `group_standings`,
     * but only for a group whose pasted order is a full permutation of the tied cluster the default
     * identified — otherwise keep the seed-order default. A group without a genuine tie has no
     * ordering row, so it is silently skipped; this is purely a tiebreak.
     *
     * @param  array<string, list<int>>  $groupStandings  uppercased group label => stated finishing order
     */
    private function overrideGroupOrderings(Entry $entry, array $groupStandings): void
    {
        if ($groupStandings === []) {
            return;
        }

        $groupsById = $entry->pool->tournament->groups()->get()->keyBy('id');

        foreach ($entry->groupOrderings()->where('scope', OrderingScope::WithinGroup)->get() as $row) {
            $label = strtoupper($groupsById->get($row->group_id)?->name ?? '');
            $provided = array_map('intval', $groupStandings[$label] ?? []);

            if ($provided === []) {
                continue;
            }

            $tied = array_map('intval', $row->tied_team_ids);
            $ordered = array_values(array_filter($provided, fn (int $id): bool => in_array($id, $tied, true)));

            if (count($ordered) === count($tied) && array_diff($tied, $ordered) === []) {
                $row->update(['ordered_team_ids' => $ordered]);
            }
        }
    }

    /**
     * The team that advances given a score: the higher-scoring side for a decisive result, the
     * pick for a draw (when it is one of the two slot teams), null when incomplete or unresolved.
     */
    private function advancingFor(?int $home, ?int $away, ?int $slotHome, ?int $slotAway, ?int $pick): ?int
    {
        if ($home === null || $away === null) {
            return null;
        }

        if ($home > $away) {
            return $slotHome;
        }

        if ($away > $home) {
            return $slotAway;
        }

        return $pick !== null && in_array($pick, [$slotHome, $slotAway], true) ? $pick : null;
    }

    /**
     * @return array<string, mixed>
     */
    private function buildPreviewPayload(Entry $entry, ParsedImport $parsed, bool $alreadyPopulated, bool $literal = false): array
    {
        $tournament = $entry->pool->tournament;
        $fixtures = $tournament->fixtures()->with('phase')->get()->keyBy('id');
        $teams = Team::all()->keyBy('id');
        $knockoutRows = $entry->knockoutPredictions()->get()->keyBy('fixture_id');

        // Only upfront pools derive the bracket from the group scores; the third-place cut, the
        // within-group ties, and the positional advancing salvage all hang off that derivation. In
        // authored mode the bracket is the JSON's own, so none of those derivation artefacts apply.
        $derivesBracket = $entry->pool->predictsKnockoutBracket();
        $showsDerivation = $derivesBracket && ! $literal;

        $matches = $parsed->matches;
        usort($matches, fn (ParsedMatch $a, ParsedMatch $b): int => $a->matchNumber <=> $b->matchNumber);

        $rows = [];
        $hasRowError = false;

        foreach ($matches as $match) {
            if ($match->fixtureId === null) {
                continue;
            }

            $fixture = $fixtures->get($match->fixtureId);
            $row = $match->isKnockout
                ? $this->knockoutRow($match, $fixture, $knockoutRows->get($match->fixtureId), $teams, $showsDerivation)
                : $this->groupRow($match, $fixture, $teams);

            $hasRowError = $hasRowError || $row['severity'] === 'error';
            $rows[] = $row;
        }

        // A phased pool predicts the official match-ups, and authored mode keeps the JSON's own
        // bracket, so in neither case is there a derived third-place ranking to compare against.
        $derivedThirds = $showsDerivation
            ? ($this->resolver->resolve($entry)->rankedThirds ?? [])
            : [];
        $jsonThirds = array_slice($parsed->thirdsTeamIds, 0, count($derivedThirds));
        $thirdsMismatch = $derivedThirds !== [] && $parsed->thirdsTeamIds !== []
            && array_diff($derivedThirds, $jsonThirds) !== [];

        // Only a derived upfront bracket carries within-group ties to report; authored mode bypasses
        // the derivation entirely.
        $groupTies = $showsDerivation
            ? $this->groupTiesReport($entry, $parsed, $teams)
            : [];

        $hasErrors = $hasRowError
            || $parsed->unknownMatchNumbers !== []
            || $parsed->unknownTeamCodes !== [];

        return [
            'rows' => $rows,
            'banner' => [
                'unknown_match_numbers' => $parsed->unknownMatchNumbers,
                'unknown_team_codes' => $parsed->unknownTeamCodes,
                'missing_match_numbers' => $this->missingGroupMatchNumbers($tournament, $parsed),
                'already_populated' => $alreadyPopulated,
                'thirds_mismatch' => $thirdsMismatch,
            ],
            'thirds' => [
                'json' => array_values(array_filter(array_map(
                    fn (int $id): ?array => $this->teamRef($teams->get($id)),
                    $parsed->thirdsTeamIds,
                ))),
                'derived' => array_values(array_filter(array_map(
                    fn (int $id): ?array => $this->teamRef($teams->get($id)),
                    $derivedThirds,
                ))),
            ],
            'counts' => [
                'group' => count(array_filter($rows, fn (array $row): bool => ! $row['is_knockout'])),
                'knockout' => count(array_filter($rows, fn (array $row): bool => $row['is_knockout'])),
            ],
            'group_ties' => $groupTies,
            'has_errors' => $hasErrors,
            // Whether this preview reflects the JSON's bracket stored verbatim (authored mode) rather
            // than the FIFA-derived one — drives the review screen's mode banner and commit payload.
            'literal' => $literal,
            // Whether authored mode is applicable at all — only upfront pools derive a bracket to
            // override. Gates the "Import the bracket as authored" offer on the review screen.
            'can_author' => $derivesBracket,
        ];
    }

    /**
     * One row per group that finished with a genuine score-tie (a within-group ordering exists),
     * carrying the final applied order and whether the user's `group_standings` resolved it (vs the
     * seed-order default). Read after the sandbox apply, so `ordered_team_ids` already reflects any
     * override. Lets the admin see whether their pasted standings took effect.
     *
     * @param  Collection<int, Team>  $teams
     * @return list<array{group: string, resolved_by_standings: bool, teams: list<array<string, mixed>>}>
     */
    private function groupTiesReport(Entry $entry, ParsedImport $parsed, Collection $teams): array
    {
        $groupsById = $entry->pool->tournament->groups()->get()->keyBy('id');

        $report = [];

        foreach ($entry->groupOrderings()->where('scope', OrderingScope::WithinGroup)->get() as $row) {
            $group = $groupsById->get($row->group_id);

            if ($group === null) {
                continue;
            }

            $tied = array_map('intval', $row->tied_team_ids);
            $provided = array_map('intval', $parsed->groupStandings[strtoupper($group->name)] ?? []);
            $ordered = array_values(array_filter($provided, fn (int $id): bool => in_array($id, $tied, true)));
            $resolved = count($ordered) === count($tied) && array_diff($tied, $ordered) === [];

            $report[] = [
                'group' => $group->name,
                'resolved_by_standings' => $resolved,
                'teams' => array_values(array_filter(array_map(
                    fn (int $id): ?array => $this->teamRef($teams->get($id)),
                    array_map('intval', $row->ordered_team_ids),
                ))),
            ];
        }

        return $report;
    }

    /**
     * @param  Collection<int, Team>  $teams
     * @return array<string, mixed>
     */
    private function groupRow(ParsedMatch $match, ?Fixture $fixture, Collection $teams): array
    {
        $home = $teams->get($fixture?->home_team_id);
        $away = $teams->get($fixture?->away_team_id);

        $flags = [];

        if (! $match->hasBothGoals()) {
            $flags[] = 'score_missing';
        }

        if ($this->matchupDiffers($match->homeTeamId, $match->awayTeamId, $fixture?->home_team_id, $fixture?->away_team_id)) {
            $flags[] = 'matchup_mismatch';
        }

        return [
            'match_number' => $match->matchNumber,
            'fixture_id' => $match->fixtureId,
            'phase' => $fixture?->phase?->name,
            'is_knockout' => false,
            'home' => $this->teamRef($home),
            'away' => $this->teamRef($away),
            'json_home' => $this->teamRef($teams->get($match->homeTeamId)),
            'json_away' => $this->teamRef($teams->get($match->awayTeamId)),
            'home_goals' => $match->homeGoals,
            'away_goals' => $match->awayGoals,
            'advancing' => null,
            'flags' => $flags,
            'severity' => $this->severity($flags),
        ];
    }

    /**
     * @param  Collection<int, Team>  $teams
     * @return array<string, mixed>
     */
    private function knockoutRow(ParsedMatch $match, ?Fixture $fixture, ?KnockoutPrediction $prediction, Collection $teams, bool $derivesBracket): array
    {
        $slotHome = $prediction?->predicted_home_team_id;
        $slotAway = $prediction?->predicted_away_team_id;
        $slotKnown = $slotHome !== null && $slotAway !== null;

        $flags = [];

        if (! $slotKnown) {
            $flags[] = 'knockout_unreachable';
        }

        if ($slotKnown && $this->matchupDiffers($match->homeTeamId, $match->awayTeamId, $slotHome, $slotAway)) {
            $flags[] = 'matchup_mismatch';
        }

        $advancesOutOfMatch = $match->advancesTeamId !== null && $slotKnown
            && ! in_array($match->advancesTeamId, [$slotHome, $slotAway], true);

        if ($advancesOutOfMatch) {
            $flags[] = 'advances_not_in_match';
        }

        $bothGoals = $match->hasBothGoals();
        $isDraw = $bothGoals && $match->homeGoals === $match->awayGoals;

        if ($slotKnown && $isDraw && $match->advancesTeamId === null) {
            $flags[] = 'advances_missing_on_draw';
        }

        // When the pick isn't even in the match the out-of-match flag is the root issue; the score
        // contradiction is a redundant consequence, so don't pile it on.
        if (! $advancesOutOfMatch && $slotKnown && $bothGoals && ! $isDraw && $match->advancesTeamId !== null
            && $match->advancesTeamId !== $prediction?->advancing_team_id) {
            $flags[] = 'advances_contradicts_score';
        }

        return [
            'match_number' => $match->matchNumber,
            'fixture_id' => $match->fixtureId,
            'phase' => $fixture?->phase?->name,
            'is_knockout' => true,
            'home' => $this->teamRef($teams->get($slotHome)),
            'away' => $this->teamRef($teams->get($slotAway)),
            'json_home' => $this->teamRef($teams->get($match->homeTeamId)),
            'json_away' => $this->teamRef($teams->get($match->awayTeamId)),
            'json_advances' => $advancesOutOfMatch ? $this->teamRef($teams->get($match->advancesTeamId)) : null,
            'position_advance' => $advancesOutOfMatch && $derivesBracket
                ? $this->positionAdvance($match, $slotHome, $slotAway, $teams)
                : null,
            'home_goals' => $match->homeGoals,
            'away_goals' => $match->awayGoals,
            'advancing' => $this->teamRef($teams->get($prediction?->advancing_team_id)),
            'flags' => $flags,
            'severity' => $this->severity($flags),
        ];
    }

    /**
     * For an out-of-match advancing pick (upfront only): the real derived team on the same side the JSON
     * listed that pick on — its home_team => the derived home, its away_team => the derived away. Null
     * when the pick isn't even one of the two teams the JSON typed for the match, so there is no side to
     * borrow. The admin opts into using this in the review screen ({@see backfill-review}).
     *
     * @param  Collection<int, Team>  $teams
     * @return array{side: string, team: array<string, mixed>}|null
     */
    private function positionAdvance(ParsedMatch $match, int $slotHome, int $slotAway, Collection $teams): ?array
    {
        $side = null;

        if ($match->advancesCode !== null && $match->advancesCode === $match->homeCode) {
            $side = 'home';
        } elseif ($match->advancesCode !== null && $match->advancesCode === $match->awayCode) {
            $side = 'away';
        }

        if ($side === null) {
            return null;
        }

        return [
            'side' => $side,
            'team' => $this->teamRef($teams->get($side === 'home' ? $slotHome : $slotAway)),
        ];
    }

    /**
     * Whether two match-ups are different teams (order-independent), when both are fully known.
     */
    private function matchupDiffers(?int $aHome, ?int $aAway, ?int $bHome, ?int $bAway): bool
    {
        if ($aHome === null || $aAway === null || $bHome === null || $bAway === null) {
            return false;
        }

        $a = [$aHome, $aAway];
        $b = [$bHome, $bAway];
        sort($a);
        sort($b);

        return $a !== $b;
    }

    /**
     * @param  list<string>  $flags
     */
    private function severity(array $flags): string
    {
        if (in_array('advances_not_in_match', $flags, true)) {
            return 'error';
        }

        return $flags === [] ? 'ok' : 'warning';
    }

    /**
     * The group fixture match numbers the blob did not include — a hint that the paste is partial.
     *
     * @return list<int>
     */
    private function missingGroupMatchNumbers(Tournament $tournament, ParsedImport $parsed): array
    {
        $provided = array_map(fn (ParsedMatch $match): int => $match->matchNumber, $parsed->matches);
        $groupNumbers = $tournament->groupFixtures()->pluck('match_number')->all();

        return array_values(array_map('intval', array_diff($groupNumbers, $provided)));
    }

    /**
     * @return array{id: int, name: string, code: ?string, flag_url: string}|null
     */
    private function teamRef(?Team $team): ?array
    {
        if ($team === null) {
            return null;
        }

        return [
            'id' => $team->id,
            'name' => $team->name,
            'code' => $team->code,
            'flag_url' => $team->flag_url,
        ];
    }

    /**
     * Load every team referenced by the blob, keyed by uppercased code for O(1) lookup.
     *
     * @param  array<int, mixed>  $rawMatches
     * @param  array<int, mixed>  $rawThirds
     * @param  array<int, mixed>  $rawStandings
     * @return Collection<string, Team>
     */
    private function teamsByCode(array $rawMatches, array $rawThirds, array $rawStandings = []): Collection
    {
        $codes = [];

        foreach ($rawMatches as $raw) {
            if (! is_array($raw)) {
                continue;
            }

            foreach (['home_team', 'away_team', 'advances'] as $key) {
                $code = $this->code($raw[$key] ?? null);

                if ($code !== null) {
                    $codes[] = $code;
                }
            }
        }

        foreach ($rawThirds as $raw) {
            $code = $this->code(is_array($raw) ? ($raw['team'] ?? null) : $raw);

            if ($code !== null) {
                $codes[] = $code;
            }
        }

        foreach ($rawStandings as $raw) {
            $standings = is_array($raw) && is_array($raw['standings'] ?? null) ? $raw['standings'] : [];

            foreach ($standings as $rawCode) {
                $code = $this->code($rawCode);

                if ($code !== null) {
                    $codes[] = $code;
                }
            }
        }

        return Team::whereIn('code', array_values(array_unique($codes)))->get()->keyBy('code');
    }

    private function code(mixed $value): ?string
    {
        if (! is_string($value)) {
            return null;
        }

        $code = strtoupper(trim($value));

        return $code === '' ? null : $code;
    }

    private function intOrNull(mixed $value): ?int
    {
        return $value === null || $value === '' ? null : (int) $value;
    }
}
