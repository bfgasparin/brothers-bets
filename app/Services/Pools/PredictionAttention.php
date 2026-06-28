<?php

namespace App\Services\Pools;

use App\Enums\PhaseKey;
use App\Enums\PhaseType;
use App\Enums\PredictionWindowStatus;
use App\Enums\TournamentStatus;
use App\Http\Controllers\PoolController;
use App\Models\Entry;
use App\Models\Fixture;
use App\Models\Pool;
use App\Services\Predictions\PredictionWindowResolver;
use App\Services\Predictions\TieResolutionState;

/**
 * The single source of truth for "does this player have outstanding work in an open prediction
 * window?" — consumed both by the always-shared sidebar ({@see JoinedPools}, lean boolean) and the
 * pool page ({@see PoolController::show()}, rich per-window summary).
 *
 * Attention spans every open window: an upfront pool's group-stage scores and the ties the player
 * must still break, and each open knockout round of a phased pool whose picks are unfinished. Bound
 * scoped in the container so the sidebar and the page share one instance per request and never
 * resolve the same bracket twice (results are memoised per pool + entry).
 */
class PredictionAttention
{
    /**
     * Memoised summaries keyed by "{poolId}:{entryId}", so the middleware-built sidebar and the
     * controller-built banner don't each pay for the (heavy) bracket/window resolution.
     *
     * @var array<string, AttentionSummary>
     */
    private array $cache = [];

    public function __construct(
        private readonly PredictionWindowResolver $windowResolver = new PredictionWindowResolver,
        private readonly TieResolutionState $tieResolution = new TieResolutionState,
    ) {}

    /**
     * Whether the pool wants the player's attention — the lean path for the sidebar dot.
     */
    public function needsAttention(Pool $pool, ?Entry $entry): bool
    {
        return $this->summary($pool, $entry)->needsAttention;
    }

    /**
     * The full breakdown of every open window with unfinished work — the rich path for the banner.
     */
    public function summary(Pool $pool, ?Entry $entry): AttentionSummary
    {
        $key = $pool->id.':'.($entry?->id ?? 'none');

        return $this->cache[$key] ??= $this->compute($pool, $entry);
    }

    /**
     * Whether the player has finished every prediction in a currently-open window — the signal that
     * drives the predict page's celebration modal and its calm "all set" banner, and the inverse of
     * {@see needsAttention()}. That boolean is false BOTH when everything is done AND when nothing is
     * open, so completion pairs "no outstanding work" with "at least one window is actually open" (a
     * window the player could still be filling). When complete, {@see summary()} lists no windows, so
     * the open windows' labels and deadlines are resolved separately by {@see openWindows()}.
     */
    public function completion(Pool $pool, ?Entry $entry): CompletionSummary
    {
        if ($entry === null) {
            return new CompletionSummary(false);
        }

        $openWindows = $this->openWindows($pool);

        if ($openWindows === [] || $this->summary($pool, $entry)->needsAttention) {
            return new CompletionSummary(false);
        }

        return new CompletionSummary(true, $openWindows);
    }

    private function compute(Pool $pool, ?Entry $entry): AttentionSummary
    {
        if ($entry === null) {
            return new AttentionSummary(false);
        }

        // An organizer-authored bracket is finished and uneditable, so it never wants attention —
        // don't nag the player to "finish" picks they can't open.
        if ($entry->hasAuthoredBracket()) {
            return new AttentionSummary(false);
        }

        if ($pool->usesPerMatchPredictionWindows()) {
            return $this->perMatchSummary($pool, $entry);
        }

        return $pool->usesPhasedPredictionWindows()
            ? $this->phasedSummary($pool, $entry)
            : $this->upfrontSummary($pool, $entry);
    }

    /**
     * The pool's currently-open prediction windows with their display label and lock deadline,
     * independent of whether they are finished — the same open-window set the attention summaries
     * walk, but without the "missing" detail (which is empty once complete). Upfront pools collapse
     * to a single window (the whole bracket locks with the group stage); phased pools list the group
     * stage plus each open knockout round.
     *
     * @return list<array{phase_key: string, label: string, deadline: ?string}>
     */
    private function openWindows(Pool $pool): array
    {
        if ($pool->usesPerMatchPredictionWindows()) {
            return $this->perMatchOpenWindows($pool);
        }

        if (! $pool->usesPhasedPredictionWindows()) {
            if (! $pool->acceptsPredictions()) {
                return [];
            }

            return [[
                'phase_key' => PhaseKey::Group->value,
                'label' => 'Your bracket',
                'deadline' => $pool->predictionsLockAt()?->toIso8601String(),
            ]];
        }

        $windows = [];

        if ($pool->acceptsPredictions()) {
            $windows[] = [
                'phase_key' => PhaseKey::Group->value,
                'label' => 'Group stage',
                'deadline' => $pool->predictionsLockAt()?->toIso8601String(),
            ];
        }

        // Knockout rounds can only be open once the group stage has produced official results.
        if ($pool->tournament->status !== TournamentStatus::Upcoming) {
            $statuses = $this->windowResolver->windows($pool);

            foreach ($pool->tournament->phases as $phase) {
                if ($phase->type === PhaseType::Knockout
                    && ($statuses[$phase->key->value] ?? null) === PredictionWindowStatus::Open) {
                    $windows[] = [
                        'phase_key' => $phase->key->value,
                        'label' => $phase->name,
                        'deadline' => $this->windowResolver->lockAtFor($pool, $phase)?->toIso8601String(),
                    ];
                }
            }
        }

        return $windows;
    }

    /**
     * Rolling (per-match) pools open the group stage from creation and each knockout round as its
     * participants are projected, but lock each match at its own kickoff. Outstanding work is any
     * match that is still open AND unpredicted — a match that has kicked off without a pick is no
     * longer actionable, so it never nags. Group matches collapse to one "Group stage" line; each
     * open knockout round contributes its own line.
     */
    private function perMatchSummary(Pool $pool, Entry $entry): AttentionSummary
    {
        $windows = [];

        if (($group = $this->perMatchGroupWindow($pool, $entry)) !== null) {
            $windows[] = $group;
        }

        // Knockout rounds can only be open once the group stage has produced official results.
        if ($pool->tournament->status !== TournamentStatus::Upcoming) {
            $windows = [...$windows, ...$this->perMatchKnockoutWindows($pool, $entry)];
        }

        return new AttentionSummary($windows !== [], $windows);
    }

    /**
     * The "Group stage" attention line for a rolling pool: the matches still open (not yet kicked
     * off) that the player has not predicted, or null when every open group match has a score. The
     * deadline is the next group kickoff — the next match to lock.
     *
     * @return array{phase_key: string, label: string, deadline: ?string, missing_count: int, total_count: int, has_unresolved_ties: bool}|null
     */
    private function perMatchGroupWindow(Pool $pool, Entry $entry): ?array
    {
        $openFixtures = $pool->tournament->groupFixtures()->get()
            ->filter(fn (Fixture $fixture): bool => $fixture->acceptsPredictions());

        if ($openFixtures->isEmpty()) {
            return null;
        }

        $predicted = $entry->groupPredictions()
            ->whereNotNull('home_goals')
            ->whereNotNull('away_goals')
            ->pluck('fixture_id')
            ->all();

        $missing = $openFixtures->reject(fn (Fixture $fixture): bool => in_array($fixture->id, $predicted, true));

        if ($missing->isEmpty()) {
            return null;
        }

        return [
            'phase_key' => PhaseKey::Group->value,
            'label' => 'Group stage',
            'deadline' => $openFixtures->min('kicks_off_at')?->toIso8601String(),
            'missing_count' => $missing->count(),
            'total_count' => $openFixtures->count(),
            'has_unresolved_ties' => false,
        ];
    }

    /**
     * The open knockout rounds of a rolling pool with unfinished picks, each as a window line. Open
     * is judged per fixture (a knockout match opens once its own participants are projected and locks
     * at its kickoff); a pick is complete only when both goals are set.
     *
     * @return list<array{phase_key: string, label: string, deadline: ?string, missing_count: int, total_count: int, has_unresolved_ties: bool}>
     */
    private function perMatchKnockoutWindows(Pool $pool, Entry $entry): array
    {
        $entry->loadMissing('knockoutPredictions');
        $predictions = $entry->knockoutPredictions->keyBy('fixture_id');

        $tournament = $pool->tournament;
        $tournament->load(['phases' => fn ($query) => $query->orderBy('sort_order'), 'phases.fixtures']);

        $windows = [];

        foreach ($tournament->phases as $phase) {
            if ($phase->type !== PhaseType::Knockout) {
                continue;
            }

            $open = $phase->fixtures->filter(
                fn (Fixture $fixture): bool => $this->windowResolver->statusForFixture($pool, $fixture) === PredictionWindowStatus::Open,
            );

            if ($open->isEmpty()) {
                continue;
            }

            $missing = $open->filter(function (Fixture $fixture) use ($predictions): bool {
                $prediction = $predictions->get($fixture->id);

                return $prediction === null || $prediction->home_goals === null || $prediction->away_goals === null;
            });

            if ($missing->isNotEmpty()) {
                $windows[] = [
                    'phase_key' => $phase->key->value,
                    'label' => $phase->name,
                    'deadline' => $open->min('kicks_off_at')?->toIso8601String(),
                    'missing_count' => $missing->count(),
                    'total_count' => $open->count(),
                    'has_unresolved_ties' => false,
                ];
            }
        }

        return $windows;
    }

    /**
     * The currently-open windows of a rolling pool (independent of completeness), for the predict
     * page's "all set" celebration: the group stage while any group match is open, plus each knockout
     * round with an open match. Deadline is the next match in that window to lock.
     *
     * @return list<array{phase_key: string, label: string, deadline: ?string}>
     */
    private function perMatchOpenWindows(Pool $pool): array
    {
        $tournament = $pool->tournament;
        $tournament->load(['phases' => fn ($query) => $query->orderBy('sort_order'), 'phases.fixtures']);

        $windows = [];

        foreach ($tournament->phases as $phase) {
            $open = $phase->fixtures->filter(
                fn (Fixture $fixture): bool => $this->windowResolver->statusForFixture($pool, $fixture) === PredictionWindowStatus::Open,
            );

            if ($open->isEmpty()) {
                continue;
            }

            $windows[] = [
                'phase_key' => $phase->key->value,
                'label' => $phase->type === PhaseType::Group ? 'Group stage' : $phase->name,
                'deadline' => $open->min('kicks_off_at')?->toIso8601String(),
            ];
        }

        return $windows;
    }

    /**
     * Upfront pools predict the whole tournament up front in a single window, so completeness runs
     * the bracket's own cascade: every group score, then any ties the engine couldn't break, then
     * every knockout pick. Each stage gates the next (the bracket can't resolve until the group
     * stage does), and they share the one group-stage lock as their deadline. The stages past the
     * cheap group count only run once the group stage is complete, so the sidebar stays cheap.
     */
    private function upfrontSummary(Pool $pool, Entry $entry): AttentionSummary
    {
        if (! $pool->acceptsPredictions()) {
            return new AttentionSummary(false);
        }

        // 1) Group scores — nothing downstream resolves until every group fixture is predicted.
        $groupTotal = $this->groupFixtureCount($pool);
        $groupMissing = max(0, $groupTotal - $this->groupPredictionCount($entry));

        if ($groupMissing > 0) {
            return new AttentionSummary(true, [$this->groupWindow($pool, $groupMissing, $groupTotal, false)]);
        }

        // 2) Ties — an unresolved standings tie blocks the bracket from resolving at all.
        if ($this->tieResolution->forEntry($entry)->blocked()) {
            return new AttentionSummary(true, [$this->groupWindow($pool, 0, $groupTotal, true)]);
        }

        // 3) Knockout bracket — with the group stage resolved, every knockout fixture still needs a pick.
        $knockoutTotal = $this->knockoutFixtureCount($pool);
        $knockoutMissing = max(0, $knockoutTotal - $this->knockoutPredictionCount($entry));

        if ($knockoutMissing > 0) {
            return new AttentionSummary(true, [[
                'phase_key' => 'knockout',
                'label' => 'Knockout bracket',
                'deadline' => $pool->predictionsLockAt()?->toIso8601String(),
                'missing_count' => $knockoutMissing,
                'total_count' => $knockoutTotal,
                'has_unresolved_ties' => false,
            ]]);
        }

        return new AttentionSummary(false);
    }

    /**
     * The single "Group stage" window line shared by the incomplete-scores and unresolved-ties cases.
     *
     * @return array{phase_key: string, label: string, deadline: ?string, missing_count: int, total_count: int, has_unresolved_ties: bool}
     */
    private function groupWindow(Pool $pool, int $missing, int $total, bool $hasUnresolvedTies): array
    {
        return [
            'phase_key' => PhaseKey::Group->value,
            'label' => 'Group stage',
            'deadline' => $pool->predictionsLockAt()?->toIso8601String(),
            'missing_count' => $missing,
            'total_count' => $total,
            'has_unresolved_ties' => $hasUnresolvedTies,
        ];
    }

    /**
     * Phased pools open the group stage on the pool lock, then each knockout round as its real
     * participants land. Each currently-open window with unfinished picks contributes a line. Phased
     * pools predict the official bracket, so standings ties never block — only missing picks do.
     */
    private function phasedSummary(Pool $pool, Entry $entry): AttentionSummary
    {
        $windows = [];

        if ($pool->acceptsPredictions()) {
            $total = $this->groupFixtureCount($pool);
            $missing = max(0, $total - $this->groupPredictionCount($entry));

            if ($missing > 0) {
                $windows[] = [
                    'phase_key' => PhaseKey::Group->value,
                    'label' => 'Group stage',
                    'deadline' => $pool->predictionsLockAt()?->toIso8601String(),
                    'missing_count' => $missing,
                    'total_count' => $total,
                    'has_unresolved_ties' => false,
                ];
            }
        }

        // Knockout rounds can only be open once the group stage has produced official results; skip
        // the window resolution entirely while the tournament is still upcoming.
        if ($pool->tournament->status !== TournamentStatus::Upcoming) {
            $windows = [...$windows, ...$this->openKnockoutWindows($pool, $entry)];
        }

        return new AttentionSummary($windows !== [], $windows);
    }

    /**
     * The open knockout rounds of a phased pool that still have unfinished picks, each as a window
     * line. A pick is complete only when both goals are set (a blank/partial row persists with null
     * goals), so completeness is goals-based, not a row count.
     *
     * @return list<array{phase_key: string, label: string, deadline: ?string, missing_count: int, total_count: int, has_unresolved_ties: bool}>
     */
    private function openKnockoutWindows(Pool $pool, Entry $entry): array
    {
        $statuses = $this->windowResolver->windows($pool);
        $entry->loadMissing('knockoutPredictions');
        $predictions = $entry->knockoutPredictions->keyBy('fixture_id');

        $windows = [];

        foreach ($pool->tournament->phases as $phase) {
            if ($phase->type !== PhaseType::Knockout
                || ($statuses[$phase->key->value] ?? null) !== PredictionWindowStatus::Open) {
                continue;
            }

            $total = $phase->fixtures->count();
            $made = $phase->fixtures->filter(function ($fixture) use ($predictions): bool {
                $prediction = $predictions->get($fixture->id);

                return $prediction !== null && $prediction->home_goals !== null && $prediction->away_goals !== null;
            })->count();

            if ($made < $total) {
                $windows[] = [
                    'phase_key' => $phase->key->value,
                    'label' => $phase->name,
                    'deadline' => $this->windowResolver->lockAtFor($pool, $phase)?->toIso8601String(),
                    'missing_count' => $total - $made,
                    'total_count' => $total,
                    'has_unresolved_ties' => false,
                ];
            }
        }

        return $windows;
    }

    /**
     * The viewer's completed group-stage picks, preferring already-loaded data so the sidebar's one
     * list query and the pool page's eager loads aren't re-run.
     */
    private function groupPredictionCount(Entry $entry): int
    {
        $count = $entry->getAttribute('group_predictions_count');

        if ($count !== null) {
            return (int) $count;
        }

        return $entry->relationLoaded('groupPredictions')
            ? $entry->groupPredictions->count()
            : $entry->groupPredictions()->count();
    }

    private function knockoutFixtureCount(Pool $pool): int
    {
        if ($pool->tournament->relationLoaded('knockoutFixtures')) {
            return $pool->tournament->knockoutFixtures->count();
        }

        return $pool->tournament->knockoutFixtures()->count();
    }

    /**
     * The viewer's completed knockout picks. A pick is complete once its advancing team is set —
     * derived from the score on a decisive result, chosen by the player on a draw — which the
     * cascade clears whenever an upstream change invalidates it, so it is the authoritative signal.
     */
    private function knockoutPredictionCount(Entry $entry): int
    {
        if ($entry->relationLoaded('knockoutPredictions')) {
            return $entry->knockoutPredictions->whereNotNull('advancing_team_id')->count();
        }

        return $entry->knockoutPredictions()->whereNotNull('advancing_team_id')->count();
    }

    private function groupFixtureCount(Pool $pool): int
    {
        $count = $pool->tournament->getAttribute('group_fixtures_count');

        if ($count !== null) {
            return (int) $count;
        }

        if ($pool->tournament->relationLoaded('groups')) {
            return $pool->tournament->groups->sum(
                fn ($group): int => $group->relationLoaded('fixtures') ? $group->fixtures->count() : $group->fixtures()->count(),
            );
        }

        return $pool->tournament->groupFixtures()->count();
    }
}
