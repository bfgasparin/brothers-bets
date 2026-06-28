<?php

namespace App\Services\Predictions;

use App\Enums\PhaseKey;
use App\Enums\PhaseType;
use App\Enums\PredictionWindowStatus;
use App\Models\Fixture;
use App\Models\Phase;
use App\Models\Pool;
use Carbon\CarbonInterface;
use Illuminate\Database\Eloquent\Collection;

/**
 * Decides, per phase, whether a pool currently accepts predictions.
 *
 * Upfront-bracket pools have one window: every phase shares the pool's single
 * {@see Pool::acceptsPredictions()} lock. Phased-bracket pools open the group stage on that same
 * lock, then each knockout round on its own: a round is {@see PredictionWindowStatus::Pending}
 * until the official {@see OfficialBracketProjector} has filled in its real participants, then
 * {@see PredictionWindowStatus::Open} until the configured buffer
 * (scoring.prediction_lock_buffer_minutes) before its first kickoff, after which it is
 * {@see PredictionWindowStatus::Locked}.
 */
class PredictionWindowResolver
{
    /**
     * The window status of every phase, keyed by phase key value.
     *
     * @return array<string, PredictionWindowStatus>
     */
    public function windows(Pool $pool): array
    {
        $windows = [];

        foreach ($this->phases($pool) as $phase) {
            $windows[$phase->key->value] = $this->statusFor($pool, $phase);
        }

        return $windows;
    }

    public function isOpen(Pool $pool, PhaseKey $phase): bool
    {
        foreach ($this->phases($pool) as $candidate) {
            if ($candidate->key === $phase) {
                return $this->statusFor($pool, $candidate) === PredictionWindowStatus::Open;
            }
        }

        return false;
    }

    /**
     * The window status of a single fixture — the per-match source of truth for Rolling Predictions
     * pools, where each match locks at its own kickoff rather than the whole pool or round at once.
     * For every other strategy a fixture inherits its phase's window, so callers can ask per fixture
     * regardless of strategy and get the right answer.
     */
    public function statusForFixture(Pool $pool, Fixture $fixture): PredictionWindowStatus
    {
        if (! $pool->usesPerMatchPredictionWindows()) {
            return $this->phaseStatusForFixture($pool, $fixture);
        }

        if ($fixture->isGroup()) {
            return $fixture->acceptsPredictions()
                ? PredictionWindowStatus::Open
                : PredictionWindowStatus::Locked;
        }

        // A knockout match cannot be predicted until its real participants are projected onto it.
        if (! $this->participantsKnown($fixture)) {
            return PredictionWindowStatus::Pending;
        }

        return $fixture->acceptsPredictions()
            ? PredictionWindowStatus::Open
            : PredictionWindowStatus::Locked;
    }

    /**
     * The instant a single fixture's prediction window closes. Its own kickoff for a per-match pool;
     * otherwise the fixture's phase deadline {@see lockAtFor()}.
     */
    public function lockAtForFixture(Pool $pool, Fixture $fixture): ?CarbonInterface
    {
        if ($pool->usesPerMatchPredictionWindows()) {
            return $fixture->predictionsLockAt();
        }

        foreach ($this->phases($pool) as $phase) {
            if ($phase->id === $fixture->phase_id) {
                return $this->lockAtFor($pool, $phase);
            }
        }

        return null;
    }

    /**
     * The tournament's phases (in progression order) with their fixtures. Reloaded fresh because
     * fixture participants and results mutate as result batches are approved.
     *
     * @return Collection<int, Phase>
     */
    private function phases(Pool $pool): Collection
    {
        $tournament = $pool->tournament;
        $tournament->load([
            'phases' => fn ($query) => $query->orderBy('sort_order'),
            'phases.fixtures',
        ]);

        return $tournament->phases;
    }

    /**
     * The instant a phase's prediction window closes — the deadline shown in the pool reminder and
     * the "window opened" email. The pool-level lock for the group stage and every upfront phase;
     * the configured buffer before a phased knockout round's first kickoff otherwise (null when that
     * round has no scheduled kickoff yet).
     */
    public function lockAtFor(Pool $pool, Phase $phase): ?CarbonInterface
    {
        // Per-match pools have no single phase deadline — each fixture locks at its own kickoff. The
        // coarse "window closes by" (used by the round-opening email) is the phase's last kickoff,
        // the point after which nothing in it is predictable.
        if ($pool->usesPerMatchPredictionWindows()) {
            return $phase->fixtures->whereNotNull('kicks_off_at')->max('kicks_off_at');
        }

        if (! $pool->usesPhasedPredictionWindows() || $phase->type === PhaseType::Group) {
            return $pool->predictionsLockAt();
        }

        $firstKickoff = $phase->fixtures->whereNotNull('kicks_off_at')->min('kicks_off_at');

        return $firstKickoff?->copy()->subMinutes((int) config('scoring.prediction_lock_buffer_minutes'));
    }

    private function statusFor(Pool $pool, Phase $phase): PredictionWindowStatus
    {
        if ($pool->usesPerMatchPredictionWindows()) {
            return $this->perMatchPhaseStatus($phase);
        }

        // Upfront pools, and the group stage of any pool, ride the single pool-level lock.
        if (! $pool->usesPhasedPredictionWindows() || $phase->type === PhaseType::Group) {
            return $pool->acceptsPredictions()
                ? PredictionWindowStatus::Open
                : PredictionWindowStatus::Locked;
        }

        // A phased knockout round stays pending until every one of its fixtures has both real
        // participants resolved (the round is fully set).
        $fixtures = $phase->fixtures;

        $allKnown = $fixtures->isNotEmpty() && $fixtures->every(
            fn ($fixture): bool => $this->participantsKnown($fixture),
        );

        if (! $allKnown) {
            return PredictionWindowStatus::Pending;
        }

        $lock = $this->lockAtFor($pool, $phase);

        if ($lock !== null && now()->gte($lock)) {
            return PredictionWindowStatus::Locked;
        }

        return PredictionWindowStatus::Open;
    }

    /**
     * The coarse phase window for a per-match pool, derived from its fixtures: a group stage (teams
     * known from the start) is Open while any match still accepts predictions; a knockout round is
     * Open while any fixture with known participants still accepts, Pending while it has a fixture
     * whose participants are not yet projected (it can still open later), otherwise Locked. Used for
     * grouping and the "opens later" badge — actual gating is per fixture {@see statusForFixture()}.
     */
    private function perMatchPhaseStatus(Phase $phase): PredictionWindowStatus
    {
        $fixtures = $phase->fixtures;

        if ($fixtures->isEmpty()) {
            return PredictionWindowStatus::Pending;
        }

        if ($phase->type === PhaseType::Group) {
            return $fixtures->contains(fn (Fixture $fixture): bool => $fixture->acceptsPredictions())
                ? PredictionWindowStatus::Open
                : PredictionWindowStatus::Locked;
        }

        $anyOpen = $fixtures->contains(
            fn (Fixture $fixture): bool => $this->participantsKnown($fixture) && $fixture->acceptsPredictions(),
        );

        if ($anyOpen) {
            return PredictionWindowStatus::Open;
        }

        $anyPending = $fixtures->contains(fn (Fixture $fixture): bool => ! $this->participantsKnown($fixture));

        return $anyPending ? PredictionWindowStatus::Pending : PredictionWindowStatus::Locked;
    }

    /**
     * The window status of a fixture's phase, used when a non-per-match pool is asked for a single
     * fixture (it inherits its phase's window). Resolved against the resolver's freshly loaded
     * phases so the phase carries its fixtures.
     */
    private function phaseStatusForFixture(Pool $pool, Fixture $fixture): PredictionWindowStatus
    {
        foreach ($this->phases($pool) as $phase) {
            if ($phase->id === $fixture->phase_id) {
                return $this->statusFor($pool, $phase);
            }
        }

        return PredictionWindowStatus::Locked;
    }

    private function participantsKnown(Fixture $fixture): bool
    {
        return $fixture->home_team_id !== null && $fixture->away_team_id !== null;
    }
}
