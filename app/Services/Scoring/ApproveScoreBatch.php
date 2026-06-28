<?php

namespace App\Services\Scoring;

use App\Enums\BatchStatus;
use App\Enums\FixtureStatus;
use App\Enums\ProposalStatus;
use App\Models\ScoreBatch;
use App\Models\ScoreProposal;
use App\Models\User;
use App\Services\Live\CloseLiveScoreboard;
use App\Services\Predictions\OfficialBracketProjector;
use App\Services\Predictions\PredictionWindowResolver;
use Illuminate\Support\Facades\DB;

/**
 * Applies an approved batch of proposed scores, end to end and atomically: it writes each
 * (non-rejected) proposal onto its fixture and re-projects the official bracket from the new
 * results. The results are shared by every pool played over the tournament, so it then cascades
 * to each pool — recomputing its entries' points and snapshotting its leaderboard ranks so
 * movement arrows have a baseline. Running it again after a correction simply re-applies and
 * recomputes.
 */
class ApproveScoreBatch
{
    public function __construct(
        private readonly OfficialBracketProjector $projector = new OfficialBracketProjector,
        private readonly ScoreEngine $engine = new ScoreEngine,
        private readonly RankSnapshotter $snapshotter = new RankSnapshotter,
        private readonly LeaderboardNotifier $notifier = new LeaderboardNotifier,
        private readonly PredictionWindowResolver $windowResolver = new PredictionWindowResolver,
        private readonly WindowOpeningNotifier $windowOpeningNotifier = new WindowOpeningNotifier,
        private readonly CloseLiveScoreboard $liveScoreboardCloser = new CloseLiveScoreboard,
    ) {}

    public function approve(ScoreBatch $batch, User $approver): void
    {
        $tournament = $batch->tournament;
        $pools = $tournament->pools()->get();

        // Snapshot each phased pool's prediction windows before the bracket is re-projected, so we
        // can tell which knockout rounds this approval opens. Captured as plain enum values — the
        // projection below reloads and mutates the underlying phase/fixture relations.
        $windowsBefore = [];
        foreach ($pools as $pool) {
            // Phased and rolling pools both open knockout rounds as participants are projected.
            if ($pool->predictsOfficialBracket()) {
                $windowsBefore[$pool->id] = $this->windowResolver->windows($pool);
            }
        }

        DB::transaction(function () use ($batch, $tournament, $pools, $approver): void {
            $proposals = $batch->proposals()
                ->where('status', '!=', ProposalStatus::Rejected)
                ->with('fixture.liveState')
                ->get();

            foreach ($proposals as $proposal) {
                $this->applyToFixture($proposal);

                // Publishing the official result is the authoritative "match over" signal, so close
                // any live scoreboard the admin left open (e.g. they entered the score on the review
                // screen without ending the match in Live Control) — otherwise the Live Center keeps
                // treating a finished fixture as in-play. Skip a board that is already closed.
                if ($proposal->fixture->liveState?->isLive() === true) {
                    $this->liveScoreboardCloser->close($proposal->fixture);
                }

                $proposal->update(['status' => ProposalStatus::Applied]);
            }

            $batch->update([
                'status' => BatchStatus::Approved,
                'approved_at' => now(),
                'approved_by' => $approver->id,
            ]);

            // Fixtures now carry the official results; cascade the bracket once, then re-score
            // and rank each pool played over this tournament.
            $this->projector->project($tournament);

            foreach ($pools as $pool) {
                $this->engine->recompute($pool);
                $this->snapshotter->snapshot($pool);
            }
        });

        // The fixtures now reflect their official results; bring the tournament's lifecycle status
        // in line (e.g. Completed once this batch finished the last fixture). Kept outside the
        // transaction so the status-changed event can't fire and then roll back.
        $tournament->syncStatus();

        // Ranks are committed; email each pool's players about milestones and significant moves, and
        // about any phased knockout round whose prediction window this approval just opened. Kept
        // outside the transaction so a later pool's failure can't fire an email and then roll back.
        foreach ($pools as $pool) {
            $this->notifier->notify($pool);

            if (isset($windowsBefore[$pool->id])) {
                $this->windowOpeningNotifier->notifyOpenedRounds($pool, $windowsBefore[$pool->id]);
            }
        }
    }

    private function applyToFixture(ScoreProposal $proposal): void
    {
        $proposal->fixture->update([
            'home_goals' => $proposal->home_goals,
            'away_goals' => $proposal->away_goals,
            'winner_team_id' => $proposal->winner_team_id,
            'home_penalties' => $proposal->home_penalties,
            'away_penalties' => $proposal->away_penalties,
            'status' => FixtureStatus::Finished,
        ]);
    }
}
