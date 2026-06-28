<?php

namespace App\Services\Predictions;

use App\Enums\PhaseKey;
use App\Enums\PredictionWindowStatus;
use App\Models\Entry;
use App\Models\EntryGroupOrdering;
use App\Models\GroupPrediction;
use App\Models\KnockoutPrediction;
use App\Models\Phase;
use App\Models\Pool;
use App\Models\User;
use App\Services\Predictions\Import\PredictionJsonImporter;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Facades\DB;

/**
 * Copies a user's own predictions from one pool into another pool of the same tournament,
 * limited to the predictions that are valid for the destination's currently-open window(s).
 *
 * The rule is a single phase-window intersection: for every phase that is
 * {@see PredictionWindowStatus::Open} in BOTH the destination and the source pool, the source
 * user's picks for that phase overwrite the destination's. Group scorelines are universal (any
 * two pools of a tournament share the same fixtures), while knockout rounds only line up between
 * phased pools — an upfront pool's whole bracket is gated by the single group lock, so once the
 * tournament starts its knockout phases are never Open and fall out of the intersection on their
 * own.
 */
class PredictionImporter
{
    public function __construct(
        private readonly PredictionWindowResolver $windowResolver = new PredictionWindowResolver,
        private readonly BracketResolver $resolver = new BracketResolver,
    ) {}

    /**
     * Overwrite the destination entry's predictions in every phase open in both pools with the
     * same user's picks from the source pool. A no-op when the user has no entry in the source.
     */
    public function import(Entry $destinationEntry, Pool $sourcePool): void
    {
        $destination = $destinationEntry->pool;
        $sourceEntry = $sourcePool->entryFor($destinationEntry->user);

        if ($sourceEntry === null) {
            return;
        }

        $openInBoth = $this->openPhasesInBoth($destination, $sourcePool);
        $knockoutPhases = array_values(array_diff($openInBoth, [PhaseKey::Group->value]));

        // A per-match destination locks each fixture at its own kickoff, so a phase can be "open"
        // overall while some of its matches have already locked (and may be scored). Clamp the
        // clean-replace to only the fixtures still open, so a locked pick is never wiped.
        $openFixtureIds = $destination->usesPerMatchPredictionWindows()
            ? $this->openDestinationFixtureIds($destination)
            : null;

        $this->copyPredictions(
            $destination,
            $destinationEntry,
            $sourceEntry,
            in_array(PhaseKey::Group->value, $openInBoth, true),
            $knockoutPhases,
            $openFixtureIds,
        );
    }

    /**
     * Admin/bypass copy: ignore the prediction-window intersection and copy every phase the source
     * has predictions for that is STRUCTURALLY compatible with the destination strategy. Used by the
     * organizer tool that seeds one pool from a sibling pool of the same tournament, so it
     * deliberately writes into closed/locked windows.
     *
     *  - Group stage: always copied (any two pools of a tournament share the same group fixtures).
     *  - Knockout: only when the source and destination predict the SAME bracket shape — both
     *    self-derived upfront, or both official (phased/rolling). A self-derived upfront bracket is
     *    NEVER copied onto an official knockout, and vice-versa. An upfront destination still rebuilds
     *    its own bracket from the copied group scores via the cascade.
     *
     * @return bool whether anything was copied — false (a no-op) when the user has no source entry or
     *              the source holds no predictions, so a bulk run can count the players it skipped.
     */
    public function importAllCompatible(Entry $destinationEntry, Pool $sourcePool): bool
    {
        $destination = $destinationEntry->pool;
        $sourceEntry = $sourcePool->entryFor($destinationEntry->user);

        if ($sourceEntry === null) {
            return false;
        }

        $copyGroup = $sourceEntry->groupPredictions()->exists();

        // Knockout transfers only between pools of the same bracket shape; otherwise group only.
        $knockoutPhases = $destination->predictsKnockoutBracket() === $sourcePool->predictsKnockoutBracket()
            ? $this->sourceKnockoutPhasesToCopy($sourceEntry, $sourcePool, $destination)
            : [];

        if (! $copyGroup && $knockoutPhases === []) {
            return false;
        }

        // null = no per-match clamp: the admin tool overwrites locked fixtures too.
        $this->copyPredictions($destination, $destinationEntry, $sourceEntry, $copyGroup, $knockoutPhases, null);

        // The copy always rebuilds in derive-mode; a destination left "authored" by a prior backfill
        // must lose that flag, or its now-derived bracket would be wrongly frozen on the wizard.
        if ($destinationEntry->authored_bracket_at !== null) {
            $destinationEntry->forceFill(['authored_bracket_at' => null])->save();
        }

        return true;
    }

    /**
     * Whether the entry already holds the user's own predictions — a bare derived knockout
     * placeholder (participants but no score/advancing pick) does not count. Lets the admin copy
     * tool decide whether a destination entry would be overwritten without injecting the heavier
     * {@see PredictionJsonImporter::hasExistingPredictions()}.
     */
    public function hasOwnPredictions(Entry $entry): bool
    {
        return $entry->groupPredictions()->exists()
            || $entry->knockoutPredictions()
                ->where(fn (Builder $query) => $query->whereNotNull('home_goals')->orWhereNotNull('advancing_team_id'))
                ->exists();
    }

    /**
     * The shared write path for both {@see import()} (open-window intersection) and
     * {@see importAllCompatible()} (admin bypass): clean-replace the group stage, cascade an upfront
     * destination's bracket, then copy the knockout phases (re-cascading upfront). $openFixtureIds
     * clamps a per-match destination's clean-replace to its still-open fixtures; null replaces the
     * whole stage (the admin bypass deliberately writes into closed/locked fixtures).
     *
     * @param  list<string>  $knockoutPhaseKeys
     * @param  list<int>|null  $openFixtureIds
     */
    private function copyPredictions(Pool $destination, Entry $destinationEntry, Entry $sourceEntry, bool $copyGroup, array $knockoutPhaseKeys, ?array $openFixtureIds): void
    {
        DB::transaction(function () use ($destination, $destinationEntry, $sourceEntry, $copyGroup, $knockoutPhaseKeys, $openFixtureIds): void {
            if ($copyGroup) {
                $this->importGroupStage($destinationEntry, $sourceEntry, $openFixtureIds);
            }

            // Upfront pools derive the knockout bracket from the imported group scores; cascade so
            // the resolved match-ups are in place before any knockout picks are copied onto them.
            if ($destination->predictsKnockoutBracket()) {
                $this->resolver->persist($destinationEntry);
            }

            if ($knockoutPhaseKeys !== []) {
                $this->importKnockoutPhases($destination, $destinationEntry, $sourceEntry, $knockoutPhaseKeys, $openFixtureIds);

                // Re-cascade an upfront bracket so the copied advancing picks are validated against
                // the resolved slots and flow downstream to a fixed point.
                if ($destination->predictsKnockoutBracket()) {
                    $this->resolver->persist($destinationEntry);
                }
            }
        });
    }

    /**
     * The knockout phase keys to bypass-copy: those the source entry actually predicted, and — for an
     * official-bracket destination — only the rounds whose destination participants are already known
     * (a phased/rolling round with un-projected slots would otherwise store null-participant rows). An
     * upfront destination has no such constraint; its bracket is rebuilt from the copied group scores.
     *
     * @return list<string>
     */
    private function sourceKnockoutPhasesToCopy(Entry $sourceEntry, Pool $sourcePool, Pool $destination): array
    {
        $knockoutKeys = $sourcePool->tournament->phases()
            ->where('key', '!=', PhaseKey::Group->value)
            ->orderBy('sort_order')
            ->get()
            ->map(fn (Phase $phase): string => $phase->key->value)
            ->all();

        return array_values(array_filter(
            $knockoutKeys,
            fn (string $key): bool => $this->entryHasPredictionsInPhase($sourceEntry, $sourcePool, $key)
                && ($destination->predictsKnockoutBracket() || $this->destinationParticipantsKnown($destination, $key)),
        ));
    }

    /**
     * Whether the destination's official knockout participants for a round are already filled in
     * (projected from results) — so a bypass-copied pick lands on a real match-up, never null teams.
     */
    private function destinationParticipantsKnown(Pool $destination, string $phaseKey): bool
    {
        return $destination->tournament->knockoutFixtures()
            ->whereRelation('phase', 'key', $phaseKey)
            ->whereNotNull('home_team_id')
            ->whereNotNull('away_team_id')
            ->exists();
    }

    /**
     * The sibling pools the user can import into this destination right now, each with the metadata
     * the wizard needs to present and confirm the choice. A pool qualifies when the user has an
     * entry in it and, for at least one phase open in both pools, that entry holds a prediction.
     *
     * @return list<array{slug: string, name: string, source: string, accent: ?string, scoring_label: string, phase_labels: list<string>, predictions_count: int}>
     */
    public function eligibleSources(Pool $destination, User $user): array
    {
        return array_map(function (array $source): array {
            $pool = $source['pool'];

            return [
                'slug' => $pool->slug,
                'name' => $pool->name,
                'source' => $pool->source,
                'accent' => $pool->accent?->value,
                'scoring_label' => $pool->scoring_strategy->label(),
                'phase_labels' => $this->phaseLabels($pool, $source['phases']),
                'predictions_count' => $this->predictionsCount($source['entry'], $pool, $source['phases']),
            ];
        }, $this->importableSources($destination, $user));
    }

    /**
     * Whether to nudge the user to import: some phase is open in the destination with no predictions
     * of theirs yet, and an eligible source can fill it. Purely data-driven — it stops on its own
     * once the user enters a prediction (or imports), with no stored "dismissed" flag.
     */
    public function shouldSuggest(Pool $destination, User $user): bool
    {
        $sources = $this->importableSources($destination, $user);

        if ($sources === []) {
            return false;
        }

        $destinationEntry = $destination->entryFor($user);
        $coveredPhases = array_unique(array_merge(...array_map(
            fn (array $source): array => $source['phases'],
            $sources,
        )));

        foreach ($coveredPhases as $phaseKey) {
            if (! $this->entryHasPredictionsInPhase($destinationEntry, $destination, $phaseKey)) {
                return true;
            }
        }

        return false;
    }

    /**
     * The raw eligible sources for the destination and user: each the candidate pool, the user's
     * entry in it, and the phase keys (open in both pools) the entry actually has predictions for.
     *
     * @return list<array{pool: Pool, entry: Entry, phases: list<string>}>
     */
    private function importableSources(Pool $destination, User $user): array
    {
        $candidates = $destination->tournament->pools()->where('id', '!=', $destination->id)->get();

        $sources = [];

        foreach ($candidates as $candidate) {
            $entry = $candidate->entryFor($user);

            if ($entry === null) {
                continue;
            }

            $phases = array_values(array_filter(
                $this->openPhasesInBoth($destination, $candidate),
                fn (string $phaseKey): bool => $this->entryHasPredictionsInPhase($entry, $candidate, $phaseKey),
            ));

            if ($phases === []) {
                continue;
            }

            $sources[] = ['pool' => $candidate, 'entry' => $entry, 'phases' => $phases];
        }

        return $sources;
    }

    /**
     * The ids of every fixture currently open (per fixture) in a rolling destination — the set the
     * clean-replace is clamped to, so a match that has locked (and may be scored) is never wiped.
     *
     * @return list<int>
     */
    private function openDestinationFixtureIds(Pool $destination): array
    {
        $tournament = $destination->tournament;
        $ids = [];

        foreach ($tournament->groupFixtures()->get() as $fixture) {
            if ($this->windowResolver->statusForFixture($destination, $fixture) === PredictionWindowStatus::Open) {
                $ids[] = $fixture->id;
            }
        }

        foreach ($tournament->knockoutFixtures()->with('phase')->get() as $fixture) {
            if ($this->windowResolver->statusForFixture($destination, $fixture) === PredictionWindowStatus::Open) {
                $ids[] = $fixture->id;
            }
        }

        return $ids;
    }

    /**
     * The phase keys that are {@see PredictionWindowStatus::Open} in both pools right now.
     *
     * @return list<string>
     */
    private function openPhasesInBoth(Pool $destination, Pool $source): array
    {
        $destinationWindows = $this->windowResolver->windows($destination);
        $sourceWindows = $this->windowResolver->windows($source);

        return array_values(array_filter(
            array_keys($destinationWindows),
            fn (string $key): bool => $destinationWindows[$key] === PredictionWindowStatus::Open
                && ($sourceWindows[$key] ?? null) === PredictionWindowStatus::Open,
        ));
    }

    /**
     * Clean-replace the destination's group scores and tie orderings with the source's. Group
     * predictions only exist where a score was entered, so this copies exactly the user's picks.
     */
    private function importGroupStage(Entry $destinationEntry, Entry $sourceEntry, ?array $openFixtureIds): void
    {
        // A per-match destination only replaces the matches still open; otherwise the whole stage is
        // clean-replaced (and tie orderings copied, which only matter for the upfront cascade).
        if ($openFixtureIds !== null) {
            $destinationEntry->groupPredictions()->whereIn('fixture_id', $openFixtureIds)->delete();

            $sourcePredictions = $sourceEntry->groupPredictions()->whereIn('fixture_id', $openFixtureIds)->get();
        } else {
            $destinationEntry->groupPredictions()->delete();
            $destinationEntry->groupOrderings()->delete();

            $sourcePredictions = $sourceEntry->groupPredictions()->get();
        }

        foreach ($sourcePredictions as $prediction) {
            GroupPrediction::create([
                'entry_id' => $destinationEntry->id,
                'fixture_id' => $prediction->fixture_id,
                'home_goals' => $prediction->home_goals,
                'away_goals' => $prediction->away_goals,
            ]);
        }

        // A per-match pool predicts the official bracket, so it has no group tie orderings to copy.
        if ($openFixtureIds !== null) {
            return;
        }

        // Tie orderings are keyed by tournament structure (group + team ids), identical across
        // pools, so they copy verbatim. They are load-bearing for the upfront cascade: without
        // them an unresolved tie could resolve differently and wipe a copied advancing pick.
        foreach ($sourceEntry->groupOrderings()->get() as $ordering) {
            EntryGroupOrdering::create([
                'entry_id' => $destinationEntry->id,
                'group_id' => $ordering->group_id,
                'scope' => $ordering->scope,
                'tied_team_ids' => $ordering->tied_team_ids,
                'ordered_team_ids' => $ordering->ordered_team_ids,
            ]);
        }
    }

    /**
     * Clean-replace the destination's knockout picks (score + advancing team) for every fixture in
     * the given open phases with the source's. Fixtures the source never predicted are reset to
     * null so the overwrite is a true replace.
     *
     * For a phased destination the official participants on the fixture are stamped alongside (the
     * same way the knockout save does); an upfront destination leaves the participants to the
     * cascade {@see BracketResolver::persist()}.
     *
     * @param  list<string>  $phaseKeys
     * @param  list<int>|null  $openFixtureIds  for a per-match destination, the fixtures still open
     */
    private function importKnockoutPhases(Pool $destination, Entry $destinationEntry, Entry $sourceEntry, array $phaseKeys, ?array $openFixtureIds): void
    {
        $fixtures = $destination->tournament->knockoutFixtures()->with('phase')->get()
            ->filter(fn ($fixture): bool => in_array($fixture->phase->key->value, $phaseKeys, true))
            // A per-match destination clamps to the matches still open, so a locked pick is untouched.
            ->filter(fn ($fixture): bool => $openFixtureIds === null || in_array($fixture->id, $openFixtureIds, true));

        $sourcePicks = $sourceEntry->knockoutPredictions()->get()->keyBy('fixture_id');

        foreach ($fixtures as $fixture) {
            $source = $sourcePicks->get($fixture->id);

            $attributes = [
                'home_goals' => $source?->home_goals,
                'away_goals' => $source?->away_goals,
                'advancing_team_id' => $source?->advancing_team_id,
            ];

            if (! $destination->predictsKnockoutBracket()) {
                // Phased: predicted against the official match-up, identical across pools of the
                // tournament, so the copied advancing team is always one of these participants.
                $attributes['predicted_home_team_id'] = $fixture->home_team_id;
                $attributes['predicted_away_team_id'] = $fixture->away_team_id;
            }

            KnockoutPrediction::updateOrCreate(
                ['entry_id' => $destinationEntry->id, 'fixture_id' => $fixture->id],
                $attributes,
            );
        }
    }

    /**
     * Whether the entry holds at least one of the user's own predictions in the given phase. A
     * knockout placeholder row (official participants, no score) does not count — only a row the
     * user has actually filled (a score, or an advancing pick on a draw).
     */
    private function entryHasPredictionsInPhase(?Entry $entry, Pool $pool, string $phaseKey): bool
    {
        if ($entry === null) {
            return false;
        }

        if ($phaseKey === PhaseKey::Group->value) {
            return $entry->groupPredictions()->exists();
        }

        return $entry->knockoutPredictions()
            ->whereIn('fixture_id', $this->knockoutFixtureIdsForPhase($pool, $phaseKey))
            ->where(fn (Builder $query) => $query->whereNotNull('home_goals')->orWhereNotNull('advancing_team_id'))
            ->exists();
    }

    /**
     * The phase display names for the given keys, in tournament progression order.
     *
     * @param  list<string>  $phaseKeys
     * @return list<string>
     */
    private function phaseLabels(Pool $pool, array $phaseKeys): array
    {
        return $pool->tournament->phases()
            ->whereIn('key', $phaseKeys)
            ->orderBy('sort_order')
            ->pluck('name')
            ->all();
    }

    /**
     * The number of the user's own predictions across the given phases — a completeness hint.
     *
     * @param  list<string>  $phaseKeys
     */
    private function predictionsCount(Entry $entry, Pool $pool, array $phaseKeys): int
    {
        $count = 0;

        foreach ($phaseKeys as $phaseKey) {
            if ($phaseKey === PhaseKey::Group->value) {
                $count += $entry->groupPredictions()->count();

                continue;
            }

            $count += $entry->knockoutPredictions()
                ->whereIn('fixture_id', $this->knockoutFixtureIdsForPhase($pool, $phaseKey))
                ->where(fn (Builder $query) => $query->whereNotNull('home_goals')->orWhereNotNull('advancing_team_id'))
                ->count();
        }

        return $count;
    }

    /**
     * @return list<int>
     */
    private function knockoutFixtureIdsForPhase(Pool $pool, string $phaseKey): array
    {
        return $pool->tournament->knockoutFixtures()
            ->whereRelation('phase', 'key', $phaseKey)
            ->pluck('id')
            ->all();
    }
}
