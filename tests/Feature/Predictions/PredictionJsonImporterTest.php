<?php

namespace Tests\Feature\Predictions;

use App\Enums\LeaderboardCategory;
use App\Enums\OrderingScope;
use App\Enums\PhaseKey;
use App\Models\Entry;
use App\Models\EntryGroupOrdering;
use App\Models\Pool;
use App\Models\Team;
use App\Models\Tournament;
use App\Models\User;
use App\Services\Predictions\BracketResolver;
use App\Services\Predictions\Import\CorrectedImport;
use App\Services\Predictions\Import\ParsedImport;
use App\Services\Predictions\Import\PredictionJsonImporter;
use App\Services\Predictions\OfficialBracketProjector;
use Database\Seeders\WorldCup2026Seeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Concerns\InteractsWithOfficialResults;
use Tests\Concerns\InteractsWithPredictions;
use Tests\TestCase;

class PredictionJsonImporterTest extends TestCase
{
    use InteractsWithOfficialResults;
    use InteractsWithPredictions;
    use RefreshDatabase;

    private Tournament $tournament;

    private Pool $pool;

    private PredictionJsonImporter $importer;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed(WorldCup2026Seeder::class);
        $this->tournament = Tournament::firstOrFail();
        $this->pool = $this->tournament->pools()->where('slug', 'world-cup-2026-ffa')->firstOrFail();
        $this->importer = new PredictionJsonImporter;
    }

    public function test_it_imports_a_full_bracket_from_json_matching_the_source_entry(): void
    {
        $reference = $this->buildReferenceEntry();
        $json = $this->jsonFromEntry($reference);

        $target = $this->entryFor(User::factory()->create());
        $parsed = $this->importer->parse($this->pool, $json);
        $preview = $this->importer->preview($target, $parsed);

        $this->assertFalse($preview['has_errors'], 'A self-consistent blob should raise no errors.');
        $this->assertSame([], $this->errorRows($preview));

        // Preview stores nothing.
        $this->assertSame(0, $target->groupPredictions()->count());
        $this->assertSame(0, $target->knockoutPredictions()->count());

        $this->importer->commit($target, $this->accept($parsed));

        // Group scores match the source exactly.
        foreach ($reference->groupPredictions()->get() as $source) {
            $this->assertDatabaseHas('group_predictions', [
                'entry_id' => $target->id,
                'fixture_id' => $source->fixture_id,
                'home_goals' => $source->home_goals,
                'away_goals' => $source->away_goals,
            ]);
        }

        // Every resolved knockout match-up + advancing team matches the source bracket.
        $targetKnockouts = $target->knockoutPredictions()->get()->keyBy('fixture_id');
        foreach ($reference->knockoutPredictions()->get() as $source) {
            if ($source->predicted_home_team_id === null) {
                continue;
            }

            $imported = $targetKnockouts->get($source->fixture_id);
            $this->assertNotNull($imported);
            $this->assertSame($source->predicted_home_team_id, $imported->predicted_home_team_id);
            $this->assertSame($source->predicted_away_team_id, $imported->predicted_away_team_id);
            $this->assertSame($source->advancing_team_id, $imported->advancing_team_id);
        }
    }

    public function test_commit_re_scores_the_pool_and_updates_the_board(): void
    {
        // Official group results in seed order: a seed-order prediction scores well.
        $this->recordOfficialGroupResults($this->tournament, $this->seedOrderScores());

        $reference = $this->buildReferenceEntry();
        $json = $this->jsonFromEntry($reference);

        $target = $this->entryFor(User::factory()->create());
        $this->importer->commit($target, $this->accept($this->importer->parse($this->pool, $json)));

        $target->refresh();
        $this->assertNotNull($target->total_points);
        $this->assertGreaterThan(0, $target->total_points);
        $this->assertGreaterThan(0, $this->standingFor($target, LeaderboardCategory::Overall)->value);
    }

    public function test_a_decisive_advances_pick_is_overridden_by_the_score(): void
    {
        $reference = $this->buildReferenceEntry();
        $json = $this->jsonFromEntry($reference);

        // Find a resolved knockout match (home won 1-0) and flip its "advances" to the loser while
        // keeping the decisive score — the importer must follow the score, not the contradictory pick.
        $index = $this->firstKnockoutMatchIndex($json);
        $json['matches'][$index]['advances'] = $json['matches'][$index]['away_team'];

        $target = $this->entryFor(User::factory()->create());
        $parsed = $this->importer->parse($this->pool, $json);
        $preview = $this->importer->preview($target, $parsed);

        $row = collect($preview['rows'])->firstWhere('match_number', $json['matches'][$index]['match_number']);
        $this->assertContains('advances_contradicts_score', $row['flags']);

        $this->importer->commit($target, $this->accept($parsed));

        $fixtureId = $row['fixture_id'];
        $imported = $target->knockoutPredictions()->where('fixture_id', $fixtureId)->firstOrFail();
        // Advancing follows the 1-0 score: the home (higher-scoring) team, not the JSON pick.
        $this->assertSame($imported->predicted_home_team_id, $imported->advancing_team_id);
    }

    public function test_preview_proposes_a_positional_advance_for_an_out_of_match_pick(): void
    {
        $json = $this->jsonFromEntry($this->buildReferenceEntry());

        // The JSON author dropped a stranger into the home spot of a knockout match and advanced it.
        [$row, $derivedHome, $stranger] = $this->corruptKnockoutHomePick($json);

        $target = $this->entryFor(User::factory()->create());
        $preview = $this->importer->preview($target, $this->importer->parse($this->pool, $json));

        $previewRow = collect($preview['rows'])->firstWhere('fixture_id', $row['fixture_id']);
        $this->assertContains('advances_not_in_match', $previewRow['flags']);
        // No redundant score-contradiction noise when the pick isn't even in the match.
        $this->assertNotContains('advances_contradicts_score', $previewRow['flags']);
        $this->assertSame($stranger->id, $previewRow['json_advances']['id']);

        // The stranger was the home pick, so the real home qualifier is proposed to advance.
        $this->assertNotNull($previewRow['position_advance']);
        $this->assertSame('home', $previewRow['position_advance']['side']);
        $this->assertSame($derivedHome->id, $previewRow['position_advance']['team']['id']);
    }

    public function test_committing_the_positional_advance_resolves_a_drawn_out_of_match_pick(): void
    {
        $json = $this->jsonFromEntry($this->buildReferenceEntry());
        [$row, $derivedHome] = $this->corruptKnockoutHomePick($json);

        // Make the match a draw, where the advancing pick is load-bearing (a decisive score would
        // decide it on its own).
        $matchIndex = $this->matchIndexForNumber($json, $row['match_number']);
        $json['matches'][$matchIndex]['home_goals'] = 1;
        $json['matches'][$matchIndex]['away_goals'] = 1;

        $target = $this->entryFor(User::factory()->create());
        $parsed = $this->importer->parse($this->pool, $json);

        // The default (out-of-match) pick can't be honoured on a draw — no winner.
        $previewRow = collect($this->importer->preview($target, $parsed)['rows'])->firstWhere('fixture_id', $row['fixture_id']);
        $this->assertNull($previewRow['advancing']);

        // Committing with the positional advance (what the review screen sends on consent) advances the
        // real home team.
        $knockout = $parsed->knockoutRows();
        foreach ($knockout as $i => $kr) {
            if ($kr['fixture_id'] === $row['fixture_id']) {
                $knockout[$i]['advancing_pick'] = $derivedHome->id;
            }
        }

        $this->importer->commit($target, new CorrectedImport($parsed->groupRows(), $knockout, $parsed->thirdsTeamIds, $parsed->groupStandings));

        $imported = $target->knockoutPredictions()->where('fixture_id', $row['fixture_id'])->firstOrFail();
        $this->assertSame($derivedHome->id, $imported->advancing_team_id);
    }

    public function test_authored_mode_stores_the_json_bracket_verbatim(): void
    {
        $json = $this->jsonFromEntry($this->buildReferenceEntry());
        // The author dropped a stranger into a knockout home spot (their group classification didn't
        // follow FIFA rules). Authored mode keeps it; the derived bracket would discard it.
        [$row, $derivedHome, $stranger] = $this->corruptKnockoutHomePick($json);

        $target = $this->entryFor(User::factory()->create());
        $parsed = $this->importer->parse($this->pool, $json);

        $this->importer->commit($target, $this->authored($parsed));

        $imported = $target->knockoutPredictions()->where('fixture_id', $row['fixture_id'])->firstOrFail();
        $this->assertSame($stranger->id, $imported->predicted_home_team_id, 'Authored mode stores the JSON team, not the derived qualifier.');
        $this->assertSame(
            Team::where('code', $row['away_team'])->value('id'),
            $imported->predicted_away_team_id,
            'The untouched away spot keeps the JSON team.',
        );
        // A decisive home win advances the authored home team.
        $this->assertSame($stranger->id, $imported->advancing_team_id);
        $this->assertNotNull($target->refresh()->authored_bracket_at, 'The entry is marked as an authored bracket.');

        // Contrast: re-committing in derive mode restores the FIFA-derived home and clears the mark.
        $this->importer->commit($target, $this->accept($parsed));
        $reimported = $target->knockoutPredictions()->where('fixture_id', $row['fixture_id'])->firstOrFail();
        $this->assertSame($derivedHome->id, $reimported->predicted_home_team_id);
        $this->assertNull($target->refresh()->authored_bracket_at);
    }

    public function test_authored_mode_advances_the_pick_on_a_drawn_match(): void
    {
        $json = $this->jsonFromEntry($this->buildReferenceEntry());
        [$row, , $stranger] = $this->corruptKnockoutHomePick($json);

        // A draw makes the "advances" pick load-bearing — and the pick (the stranger) is one of the
        // authored teams, so authored mode honours it.
        $matchIndex = $this->matchIndexForNumber($json, $row['match_number']);
        $json['matches'][$matchIndex]['home_goals'] = 1;
        $json['matches'][$matchIndex]['away_goals'] = 1;

        $target = $this->entryFor(User::factory()->create());
        $this->importer->commit($target, $this->authored($this->importer->parse($this->pool, $json)));

        $imported = $target->knockoutPredictions()->where('fixture_id', $row['fixture_id'])->firstOrFail();
        $this->assertSame($stranger->id, $imported->advancing_team_id);
    }

    public function test_authored_mode_entries_still_score(): void
    {
        // Group results in seed order — a seed-order prediction scores. Authored mode keeps the entry
        // in the scoring pipeline (it is not zeroed out for having an out-of-place knockout team).
        $this->recordOfficialGroupResults($this->tournament, $this->seedOrderScores());

        $json = $this->jsonFromEntry($this->buildReferenceEntry());
        $this->corruptKnockoutHomePick($json);

        $target = $this->entryFor(User::factory()->create());
        $this->importer->commit($target, $this->authored($this->importer->parse($this->pool, $json)));

        $this->assertGreaterThan(0, $target->refresh()->total_points);
    }

    public function test_authored_mode_is_a_no_op_for_a_phased_pool(): void
    {
        $phased = $this->tournament->pools()->where('slug', 'world-cup-2026-brothers')->firstOrFail();
        $this->recordOfficialGroupResults($this->tournament, $this->seedOrderScores());
        (new OfficialBracketProjector)->project($this->tournament);

        $target = $phased->entries()->create(['user_id' => User::factory()->create()->id]);
        // Authored mode is upfront-only; a phased commit ignores the flag and never marks the entry.
        $this->importer->commit($target, $this->authored($this->importer->parse($phased, $this->phasedJson())));

        $this->assertNull($target->refresh()->authored_bracket_at);
        $this->assertGreaterThan(0, $target->knockoutPredictions()->whereNotNull('predicted_home_team_id')->count());
    }

    public function test_a_phased_pool_offers_no_positional_salvage(): void
    {
        $phased = $this->tournament->pools()->where('slug', 'world-cup-2026-brothers')->firstOrFail();
        $this->recordOfficialGroupResults($this->tournament, $this->seedOrderScores());
        (new OfficialBracketProjector)->project($this->tournament);

        $json = $this->phasedJson();
        $index = $this->firstKnockoutMatchIndex($json);
        $match = $json['matches'][$index];
        $stranger = Team::whereNotIn('code', [$match['home_team'], $match['away_team']])->firstOrFail();
        $json['matches'][$index]['advances'] = $stranger->code;

        $target = $phased->entries()->create(['user_id' => User::factory()->create()->id]);
        $previewRow = collect($this->importer->preview($target, $this->importer->parse($phased, $json))['rows'])
            ->firstWhere('match_number', $match['match_number']);

        // The mismatch is still flagged, but a phased pool derives no bracket, so there is no
        // home/away side to borrow.
        $this->assertContains('advances_not_in_match', $previewRow['flags']);
        $this->assertNull($previewRow['position_advance']);
    }

    /**
     * Rewrite the first knockout match of a self-consistent blob so its home spot holds a stranger that
     * isn't in the derived match-up, and advance that stranger. Returns the (pre-mutation) blob row, the
     * real derived home team, and the stranger.
     *
     * @param  array<string, mixed>  $json  mutated in place
     * @return array{0: array<string, mixed>, 1: Team, 2: Team}
     */
    private function corruptKnockoutHomePick(array &$json): array
    {
        $index = $this->firstKnockoutMatchIndex($json);
        $row = $json['matches'][$index];

        $derivedHome = Team::where('code', $row['home_team'])->firstOrFail();
        $stranger = Team::whereNotIn('code', [$row['home_team'], $row['away_team']])->firstOrFail();

        $json['matches'][$index]['home_team'] = $stranger->code;
        $json['matches'][$index]['advances'] = $stranger->code;

        // Decorate the returned row with its fixture id for convenient lookups in the preview.
        $row['fixture_id'] = $this->tournament->fixtures()->where('match_number', $row['match_number'])->value('id');

        return [$row, $derivedHome, $stranger];
    }

    /**
     * @param  array<string, mixed>  $json
     */
    private function matchIndexForNumber(array $json, int $matchNumber): int
    {
        foreach ($json['matches'] as $index => $match) {
            if ($match['match_number'] === $matchNumber) {
                return $index;
            }
        }

        $this->fail("No match number {$matchNumber} in the blob.");
    }

    public function test_unknown_match_numbers_and_team_codes_are_flagged_as_errors(): void
    {
        $json = [
            'matches' => [
                ['match_number' => 9999, 'home_team' => 'MEX', 'away_team' => 'CAN', 'home_goals' => 1, 'away_goals' => 0],
                ['match_number' => 1, 'home_team' => 'ZZZ', 'away_team' => 'XYZ', 'home_goals' => 2, 'away_goals' => 0],
            ],
        ];

        $parsed = $this->importer->parse($this->pool, $json);

        $this->assertSame([9999], $parsed->unknownMatchNumbers);
        $this->assertEqualsCanonicalizing(['ZZZ', 'XYZ'], $parsed->unknownTeamCodes);

        $preview = $this->importer->preview($this->entryFor(User::factory()->create()), $parsed);

        $this->assertTrue($preview['has_errors']);
        $this->assertSame([9999], $preview['banner']['unknown_match_numbers']);
        $this->assertEqualsCanonicalizing(['ZZZ', 'XYZ'], $preview['banner']['unknown_team_codes']);
    }

    public function test_has_existing_predictions_detects_a_real_pick(): void
    {
        $entry = $this->entryFor(User::factory()->create());
        $this->assertFalse($this->importer->hasExistingPredictions($entry));

        $this->predictGroup($entry, $this->tournament, 'A', $this->seedOrderScores());
        $this->assertTrue($this->importer->hasExistingPredictions($entry));
    }

    public function test_a_partial_blob_flags_missing_group_matches(): void
    {
        // Only the first group fixture, nothing else.
        $fixture = $this->tournament->groupFixtures()->with(['homeTeam', 'awayTeam'])->orderBy('match_number')->firstOrFail();
        $json = [
            'matches' => [[
                'match_number' => $fixture->match_number,
                'home_team' => $fixture->homeTeam->code,
                'away_team' => $fixture->awayTeam->code,
                'home_goals' => 1,
                'away_goals' => 0,
            ]],
        ];

        $preview = $this->importer->preview($this->entryFor(User::factory()->create()), $this->importer->parse($this->pool, $json));

        $this->assertNotContains($fixture->match_number, $preview['banner']['missing_match_numbers']);
        $this->assertCount(71, $preview['banner']['missing_match_numbers']);
    }

    public function test_it_imports_a_phased_pool_against_the_official_match_ups(): void
    {
        $phased = $this->tournament->pools()->where('slug', 'world-cup-2026-brothers')->firstOrFail();

        // Official group results + the projected bracket so the knockout fixtures carry real
        // participants — what a phased pool predicts against (no self-derived bracket).
        $this->recordOfficialGroupResults($this->tournament, $this->seedOrderScores());
        (new OfficialBracketProjector)->project($this->tournament);

        $target = $phased->entries()->create(['user_id' => User::factory()->create()->id]);
        $parsed = $this->importer->parse($phased, $this->phasedJson());
        $preview = $this->importer->preview($target, $parsed);

        $this->assertFalse($preview['has_errors']);
        $this->assertSame([], $this->errorRows($preview));
        // Preview persists nothing; no derived thirds for a phased pool.
        $this->assertSame(0, $target->knockoutPredictions()->count());
        $this->assertSame([], $preview['thirds']['derived']);

        $this->importer->commit($target, $this->accept($parsed));

        // Round-of-32 predictions are stamped against the OFFICIAL participants, advancing derived
        // from the 2-1 score (home), and no tie ordering is ever written for a phased pool.
        $r32 = $this->tournament->knockoutFixtures()
            ->whereRelation('phase', 'key', PhaseKey::RoundOf32->value)
            ->whereNotNull('home_team_id')
            ->get();
        $this->assertGreaterThan(0, $r32->count());
        foreach ($r32 as $fixture) {
            $this->assertDatabaseHas('knockout_predictions', [
                'entry_id' => $target->id,
                'fixture_id' => $fixture->id,
                'predicted_home_team_id' => $fixture->home_team_id,
                'predicted_away_team_id' => $fixture->away_team_id,
                'advancing_team_id' => $fixture->home_team_id,
                'home_goals' => 2,
                'away_goals' => 1,
            ]);
        }

        $this->assertSame(0, $target->groupOrderings()->count());
        $this->assertSame(72, $target->groupPredictions()->count());
        // Group predictions match the official scores, so re-scoring lights up the board.
        $this->assertGreaterThan(0, $target->refresh()->total_points);
    }

    /**
     * A phased-pool blob: group scores equal to the official results, plus a Round-of-32 prediction
     * for every fixture whose official participants are known (home wins 2-1).
     *
     * @return array<string, mixed>
     */
    private function phasedJson(): array
    {
        $matches = [];

        foreach ($this->tournament->groupFixtures()->with(['homeTeam', 'awayTeam'])->get() as $fixture) {
            $matches[] = [
                'match_number' => $fixture->match_number,
                'home_team' => $fixture->homeTeam->code,
                'away_team' => $fixture->awayTeam->code,
                'home_goals' => $fixture->home_goals,
                'away_goals' => $fixture->away_goals,
            ];
        }

        $r32 = $this->tournament->knockoutFixtures()
            ->whereRelation('phase', 'key', PhaseKey::RoundOf32->value)
            ->whereNotNull('home_team_id')
            ->with(['homeTeam', 'awayTeam'])
            ->get();

        foreach ($r32 as $fixture) {
            $matches[] = [
                'match_number' => $fixture->match_number,
                'home_team' => $fixture->homeTeam->code,
                'away_team' => $fixture->awayTeam->code,
                'home_goals' => 2,
                'away_goals' => 1,
                'advances' => $fixture->homeTeam->code,
            ];
        }

        return ['matches' => $matches];
    }

    public function test_group_standings_break_a_within_group_tie(): void
    {
        $byPosition = $this->groupATeamsByPosition();

        // The player stated the lower seed (position 2) finishes ahead of position 1 — the reverse of
        // the seed-order default the derivation would otherwise apply to the tie.
        $standings = [$byPosition[2]->code, $byPosition[1]->code, $byPosition[3]->code, $byPosition[4]->code];
        $json = $this->blob($this->tieRule(), $standings);

        $target = $this->entryFor(User::factory()->create());
        $parsed = $this->importer->parse($this->pool, $json);
        $this->importer->commit($target, $this->accept($parsed));

        $row = $this->withinGroupOrdering($target);
        $this->assertSame(
            [$byPosition[2]->id, $byPosition[1]->id],
            array_map('intval', $row->ordered_team_ids),
            'The within-group ordering should follow the pasted standings, not seed order.',
        );

        // The resolved bracket ranks the group with the user's stated winner first.
        $resolved = (new BracketResolver)->resolve($target->fresh());
        $this->assertSame($byPosition[2]->id, $resolved->standings['A']->winner());
        $this->assertSame($byPosition[1]->id, $resolved->standings['A']->runnerUp());
    }

    public function test_a_within_group_tie_falls_back_to_seed_order_without_group_standings(): void
    {
        $byPosition = $this->groupATeamsByPosition();

        $target = $this->entryFor(User::factory()->create());
        $this->importer->commit($target, $this->accept($this->importer->parse($this->pool, $this->blob($this->tieRule()))));

        $row = $this->withinGroupOrdering($target);
        $this->assertSame(
            [$byPosition[1]->id, $byPosition[2]->id],
            array_map('intval', $row->ordered_team_ids),
            'With no pasted standings the tie keeps the seed-order default.',
        );

        $resolved = (new BracketResolver)->resolve($target->fresh());
        $this->assertSame($byPosition[1]->id, $resolved->standings['A']->winner());
    }

    public function test_group_standings_are_ignored_when_the_group_has_no_tie(): void
    {
        $byPosition = $this->groupATeamsByPosition();

        // Seed-order scores leave no tie in group A; a contradictory standings list must not override
        // a position the scores already decided.
        $standings = [$byPosition[2]->code, $byPosition[1]->code, $byPosition[3]->code, $byPosition[4]->code];
        $json = $this->blob($this->seedOrderScores(), $standings);

        $target = $this->entryFor(User::factory()->create());
        $parsed = $this->importer->parse($this->pool, $json);
        $preview = $this->importer->preview($target, $parsed);
        $this->assertFalse($preview['has_errors']);

        $this->importer->commit($target, $this->accept($parsed));

        $groupA = $this->tournament->groups()->where('name', 'A')->firstOrFail();
        $this->assertSame(
            0,
            $target->groupOrderings()->where('scope', OrderingScope::WithinGroup)->where('group_id', $groupA->id)->count(),
            'A group with no tie has no within-group ordering, so the standings are ignored.',
        );

        // The scores still decide: position 1 wins, not the pasted position 2.
        $resolved = (new BracketResolver)->resolve($target->fresh());
        $this->assertSame($byPosition[1]->id, $resolved->standings['A']->winner());
    }

    public function test_the_preview_reports_which_group_ties_the_standings_resolved(): void
    {
        $byPosition = $this->groupATeamsByPosition();
        $standings = [$byPosition[2]->code, $byPosition[1]->code, $byPosition[3]->code, $byPosition[4]->code];

        $parsed = $this->importer->parse($this->pool, $this->blob($this->tieRule(), $standings));
        $resolvedPreview = $this->importer->preview($this->entryFor(User::factory()->create()), $parsed);

        $groupATie = collect($resolvedPreview['group_ties'])->firstWhere('group', 'A');
        $this->assertNotNull($groupATie, 'The tied group A should be reported.');
        $this->assertTrue($groupATie['resolved_by_standings']);
        $this->assertSame($byPosition[2]->id, $groupATie['teams'][0]['id']);

        // The same tie with no standings is reported as unresolved (seed-order default).
        $defaultParsed = $this->importer->parse($this->pool, $this->blob($this->tieRule()));
        $defaultPreview = $this->importer->preview($this->entryFor(User::factory()->create()), $defaultParsed);
        $defaultTie = collect($defaultPreview['group_ties'])->firstWhere('group', 'A');
        $this->assertNotNull($defaultTie);
        $this->assertFalse($defaultTie['resolved_by_standings']);
    }

    /**
     * A position-based rule that leaves group positions 1 and 2 perfectly level on every score
     * tiebreak (they draw their head-to-head; each beats positions 3 and 4 by the same 1–0) so the
     * derivation cannot separate them — the only remaining separator is a manual order.
     *
     * @return callable(int, int): array{int, int}
     */
    private function tieRule(): callable
    {
        return function (int $homePosition, int $awayPosition): array {
            if (min($homePosition, $awayPosition) === 1 && max($homePosition, $awayPosition) === 2) {
                return [1, 1];
            }

            return $homePosition < $awayPosition ? [1, 0] : [0, 1];
        };
    }

    /**
     * A full group-stage blob where group A is scored by $ruleForA (every other group seed-order), with
     * an optional `group_standings` entry for group A.
     *
     * @param  callable(int, int): array{int, int}  $ruleForA
     * @param  list<string>|null  $groupAStandings  team codes in the user's stated order for group A
     * @return array<string, mixed>
     */
    private function blob(callable $ruleForA, ?array $groupAStandings = null): array
    {
        $seedOrder = $this->seedOrderScores();
        $matches = [];

        foreach ($this->tournament->groups()->orderBy('sort_order')->get() as $group) {
            $positions = $group->teams()->get()->mapWithKeys(fn (Team $team): array => [$team->id => $team->pivot->position]);
            $rule = $group->name === 'A' ? $ruleForA : $seedOrder;

            foreach ($group->fixtures()->with(['homeTeam', 'awayTeam'])->orderBy('match_number')->get() as $fixture) {
                [$home, $away] = $rule($positions[$fixture->home_team_id], $positions[$fixture->away_team_id]);

                $matches[] = [
                    'match_number' => $fixture->match_number,
                    'home_team' => $fixture->homeTeam->code,
                    'away_team' => $fixture->awayTeam->code,
                    'home_goals' => $home,
                    'away_goals' => $away,
                ];
            }
        }

        $json = ['matches' => $matches];

        if ($groupAStandings !== null) {
            $json['group_standings'] = [['group' => 'A', 'standings' => $groupAStandings]];
        }

        return $json;
    }

    /**
     * Group A's teams keyed by seed position (1–4).
     *
     * @return array<int, Team>
     */
    private function groupATeamsByPosition(): array
    {
        $group = $this->tournament->groups()->where('name', 'A')->firstOrFail();

        $byPosition = [];
        foreach ($group->teams()->get() as $team) {
            $byPosition[$team->pivot->position] = $team;
        }

        ksort($byPosition);

        return $byPosition;
    }

    private function withinGroupOrdering(Entry $entry): EntryGroupOrdering
    {
        $groupA = $this->tournament->groups()->where('name', 'A')->firstOrFail();

        return $entry->groupOrderings()
            ->where('scope', OrderingScope::WithinGroup)
            ->where('group_id', $groupA->id)
            ->firstOrFail();
    }

    private function buildReferenceEntry(): Entry
    {
        $entry = $this->entryFor(User::factory()->create());
        $this->predictAllGroups($entry, $this->tournament, $this->seedOrderScores());
        $this->advanceAllHome($entry, new BracketResolver);

        return $entry->refresh();
    }

    private function entryFor(User $user): Entry
    {
        return $this->pool->entries()->create(['user_id' => $user->id]);
    }

    /**
     * Serialise an entry's predictions into the backfill JSON shape.
     *
     * @return array<string, mixed>
     */
    private function jsonFromEntry(Entry $entry): array
    {
        $fixtures = $this->tournament->fixtures()->with(['homeTeam', 'awayTeam'])->get()->keyBy('id');
        $teams = Team::all()->keyBy('id');

        $matches = [];

        foreach ($entry->groupPredictions()->get() as $prediction) {
            $fixture = $fixtures->get($prediction->fixture_id);
            $matches[] = [
                'match_number' => $fixture->match_number,
                'home_team' => $fixture->homeTeam->code,
                'away_team' => $fixture->awayTeam->code,
                'home_goals' => $prediction->home_goals,
                'away_goals' => $prediction->away_goals,
            ];
        }

        foreach ($entry->knockoutPredictions()->get() as $prediction) {
            if ($prediction->predicted_home_team_id === null || $prediction->predicted_away_team_id === null) {
                continue;
            }

            $match = [
                'match_number' => $fixtures->get($prediction->fixture_id)->match_number,
                'home_team' => $teams->get($prediction->predicted_home_team_id)->code,
                'away_team' => $teams->get($prediction->predicted_away_team_id)->code,
                'home_goals' => $prediction->home_goals,
                'away_goals' => $prediction->away_goals,
            ];

            if ($prediction->advancing_team_id !== null) {
                $match['advances'] = $teams->get($prediction->advancing_team_id)->code;
            }

            $matches[] = $match;
        }

        $resolved = (new BracketResolver)->resolve($entry);
        $thirds = array_map(
            fn (int $id): array => ['team' => $teams->get($id)->code],
            $resolved->rankedThirds ?? [],
        );

        return ['matches' => $matches, 'third_places_classification' => $thirds];
    }

    private function accept(ParsedImport $parsed): CorrectedImport
    {
        return new CorrectedImport($parsed->groupRows(), $parsed->knockoutRows(), $parsed->thirdsTeamIds, $parsed->groupStandings);
    }

    /**
     * An "import the bracket as authored" commit: the JSON's own knockout participants, with the
     * literal flag set.
     */
    private function authored(ParsedImport $parsed): CorrectedImport
    {
        return new CorrectedImport($parsed->groupRows(), $parsed->literalKnockoutRows(), $parsed->thirdsTeamIds, $parsed->groupStandings, literal: true);
    }

    /**
     * @param  array<string, mixed>  $preview
     * @return list<int>
     */
    private function errorRows(array $preview): array
    {
        return collect($preview['rows'])
            ->where('severity', 'error')
            ->pluck('match_number')
            ->all();
    }

    /**
     * @param  array<string, mixed>  $json
     */
    private function firstKnockoutMatchIndex(array $json): int
    {
        $knockoutNumbers = $this->tournament->knockoutFixtures()->pluck('match_number')->all();

        foreach ($json['matches'] as $index => $match) {
            if (in_array($match['match_number'], $knockoutNumbers, true) && isset($match['advances'])) {
                return $index;
            }
        }

        $this->fail('No knockout match with an advancing team in the blob.');
    }
}
