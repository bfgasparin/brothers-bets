<?php

namespace Tests\Feature\Scoring;

use App\Enums\BatchStatus;
use App\Enums\FixtureStatus;
use App\Enums\LeaderboardCategory;
use App\Enums\LiveStatus;
use App\Enums\PhaseKey;
use App\Enums\PhaseType;
use App\Enums\PoolAccent;
use App\Enums\ProposalStatus;
use App\Enums\ScoringStrategy;
use App\Enums\TournamentStatus;
use App\Models\Entry;
use App\Models\Fixture;
use App\Models\FixtureLiveState;
use App\Models\Pool;
use App\Models\ScoreBatch;
use App\Models\ScoreProposal;
use App\Models\Tournament;
use App\Models\User;
use App\Notifications\PredictionWindowOpenedNotification;
use App\Notifications\TopOfLeaderboardNotification;
use App\Services\Live\EndLiveMatch;
use Database\Seeders\WorldCup2026Seeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Notification;
use Tests\Concerns\InteractsWithOfficialResults;
use Tests\Concerns\InteractsWithPredictions;
use Tests\TestCase;

class ApproveScoreBatchTest extends TestCase
{
    use InteractsWithOfficialResults;
    use InteractsWithPredictions;
    use RefreshDatabase;

    private Tournament $tournament;

    private Pool $pool;

    private Entry $entry;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed(WorldCup2026Seeder::class);
        $this->tournament = Tournament::firstOrFail();
        $this->pool = $this->tournament->pools()->where('slug', 'world-cup-2026-ffa')->firstOrFail();
        $this->entry = Entry::factory()->for($this->pool)->for(User::factory())->create();
        $this->predictAllGroups($this->entry, $this->tournament, $this->seedOrderScores());
    }

    public function test_approving_a_batch_writes_scores_projects_the_bracket_scores_and_ranks(): void
    {
        $batch = $this->openBatch();
        $this->proposeAllGroupResults($batch);
        $this->resolveProjectedTies($this->tournament, $batch);

        // Approval keeps the admin on the review screen (re-rendered to its post-approval state).
        $this->actingAs($this->admin())
            ->post(route('manage.scores.approve', $this->tournament))
            ->assertRedirect(route('manage.scores.review', $this->tournament));

        // Official scores are written onto the fixtures.
        $firstGroupFixture = $this->tournament->groupFixtures()->orderBy('match_number')->first();
        $this->assertSame(FixtureStatus::Finished, $firstGroupFixture->fresh()->status);
        $this->assertNotNull($firstGroupFixture->fresh()->home_goals);

        // The official bracket is projected (R32 participants now known).
        $r32 = $this->knockoutFixture($this->tournament, 'R32-1');
        $this->assertNotNull($r32->fresh()->home_team_id);

        // Points and ranks are computed for the entry.
        $this->assertNotNull($this->entry->fresh()->total_points);
        $this->assertSame(1, $this->entry->fresh()->rank);

        // Every leaderboard now has a ranked standing for the entry, and the Overall board's rank
        // mirrors the entry's rank.
        $this->assertSame(3, $this->entry->standings()->count());
        $overall = $this->standingFor($this->entry, LeaderboardCategory::Overall);
        $this->assertSame(1, $overall->rank);
        $this->assertSame($this->entry->fresh()->total_points, $overall->value);
        $this->assertSame(1, $this->standingFor($this->entry, LeaderboardCategory::MatchWinners)->rank);

        // The batch and its proposals are marked applied.
        $this->assertSame(BatchStatus::Approved, $batch->fresh()->status);
        $this->assertSame(ProposalStatus::Applied, $batch->proposals()->first()->fresh()->status);
    }

    public function test_approving_results_advances_the_tournament_to_in_progress(): void
    {
        $batch = $this->openBatch();
        $this->proposeAllGroupResults($batch);
        $this->resolveProjectedTies($this->tournament, $batch);

        $this->assertSame(TournamentStatus::Upcoming, $this->tournament->fresh()->status);

        $this->actingAs($this->admin())->post(route('manage.scores.approve', $this->tournament));

        // The group results are in, but the knockout fixtures are still scheduled — the tournament
        // is underway, not finished.
        $this->assertSame(TournamentStatus::InProgress, $this->tournament->fresh()->status);
    }

    public function test_approving_a_batch_notifies_the_new_leader(): void
    {
        Notification::fake();

        $batch = $this->openBatch();
        $this->proposeAllGroupResults($batch);
        $this->resolveProjectedTies($this->tournament, $batch);

        $this->actingAs($this->admin())
            ->post(route('manage.scores.approve', $this->tournament));

        // The only entry becomes #1, so its owner receives the milestone email.
        Notification::assertSentTo($this->entry->user, TopOfLeaderboardNotification::class);
    }

    public function test_opening_a_phased_knockout_window_emails_every_entrant(): void
    {
        Notification::fake();

        [$phased, $entrants] = $this->phasedPoolWithEntrants();
        $this->openRoundOf32After();

        $batch = $this->openBatch();
        $this->proposeAllGroupResults($batch);
        $this->resolveProjectedTies($this->tournament, $batch);

        $this->actingAs($this->admin())->post(route('manage.scores.approve', $this->tournament));

        $roundName = $this->tournament->phases()->where('key', PhaseKey::RoundOf32->value)->value('name');

        foreach ($entrants as $user) {
            Notification::assertSentTo(
                $user,
                PredictionWindowOpenedNotification::class,
                fn (PredictionWindowOpenedNotification $notification): bool => $notification->roundNameEn === $roundName
                    && $notification->phaseKey === PhaseKey::RoundOf32
                    && $notification->poolSlug === $phased->slug
                    && $notification->deadline !== null,
            );
        }

        // The upfront pool has a single window that never re-opens, so its player is never emailed.
        Notification::assertNotSentTo($this->entry->user, PredictionWindowOpenedNotification::class);
    }

    public function test_opening_a_rolling_knockout_window_emails_every_entrant(): void
    {
        Notification::fake();

        $rolling = Pool::factory()->rollingBracket()->create([
            'tournament_id' => $this->tournament->id,
            'slug' => 'rolling-extra',
        ]);
        $entrants = [];
        foreach (range(1, 2) as $ignored) {
            $user = User::factory()->create();
            Entry::factory()->for($rolling)->for($user)->create();
            $entrants[] = $user;
        }
        $this->openRoundOf32After();

        $batch = $this->openBatch();
        $this->proposeAllGroupResults($batch);
        $this->resolveProjectedTies($this->tournament, $batch);

        $this->actingAs($this->admin())->post(route('manage.scores.approve', $this->tournament));

        // A rolling pool opens each knockout round when its participants are projected (matches lock
        // per fixture, but the round opens together), so it emails exactly like a phased pool.
        foreach ($entrants as $user) {
            Notification::assertSentTo(
                $user,
                PredictionWindowOpenedNotification::class,
                fn (PredictionWindowOpenedNotification $notification): bool => $notification->phaseKey === PhaseKey::RoundOf32
                    && $notification->poolSlug === $rolling->slug,
            );
        }
    }

    public function test_a_phased_window_email_is_not_resent_when_a_correction_is_approved(): void
    {
        Notification::fake();

        [, $entrants] = $this->phasedPoolWithEntrants();
        $this->openRoundOf32After();

        $batch = $this->openBatch();
        $this->proposeAllGroupResults($batch);
        $this->resolveProjectedTies($this->tournament, $batch);
        $this->actingAs($this->admin())->post(route('manage.scores.approve', $this->tournament));

        // Re-approve a correction while the Round of 32 is still open: it must not email again.
        $fixture = $this->tournament->groupFixtures()->orderBy('match_number')->first();
        $newBatch = $this->openBatch();
        ScoreProposal::create([
            'score_batch_id' => $newBatch->id,
            'fixture_id' => $fixture->id,
            'home_goals' => 3,
            'away_goals' => 0,
            'winner_team_id' => $fixture->home_team_id,
            'status' => ProposalStatus::Pending,
        ]);
        $this->resolveProjectedTies($this->tournament, $newBatch);
        $this->actingAs($this->admin())->post(route('manage.scores.approve', $this->tournament));

        foreach ($entrants as $user) {
            Notification::assertSentToTimes($user, PredictionWindowOpenedNotification::class, 1);
        }
    }

    public function test_the_window_opened_email_renders_both_views(): void
    {
        $phased = $this->tournament->pools()->where('slug', 'world-cup-2026-brothers')->firstOrFail();
        $notification = new PredictionWindowOpenedNotification(
            $phased->name,
            $phased->slug,
            $phased->source,
            $phased->accent ?? PoolAccent::Pitch,
            PhaseKey::RoundOf32,
            'Round of 32',
            now()->addDays(3),
        );

        $mail = $notification->toMail(User::factory()->create(['name' => 'Sam']));

        $html = view($mail->view[0], $mail->viewData)->render();
        $text = view($mail->view[1], $mail->viewData)->render();

        $this->assertStringContainsString('Round of 32', $html);
        $this->assertStringContainsString('Make your picks', $html);
        $this->assertStringContainsString('Round of 32', $text);
    }

    public function test_a_knockout_round_already_past_its_deadline_locks_without_emailing(): void
    {
        Notification::fake();

        [, $entrants] = $this->phasedPoolWithEntrants();
        // The Round of 32 kicks off in the past, so once projected it is already locked, not open.
        $this->tournament->phases()->where('key', PhaseKey::RoundOf32->value)->firstOrFail()
            ->fixtures()->update(['kicks_off_at' => now()->subHour()]);

        $batch = $this->openBatch();
        $this->proposeAllGroupResults($batch);
        $this->resolveProjectedTies($this->tournament, $batch);
        $this->actingAs($this->admin())->post(route('manage.scores.approve', $this->tournament));

        foreach ($entrants as $user) {
            Notification::assertNotSentTo($user, PredictionWindowOpenedNotification::class);
        }
    }

    public function test_re_approving_after_a_correction_recomputes_cleanly(): void
    {
        $batch = $this->openBatch();
        $this->proposeAllGroupResults($batch);
        $this->resolveProjectedTies($this->tournament, $batch);

        $this->actingAs($this->admin())->post(route('manage.scores.approve', $this->tournament));
        $firstTotal = $this->entry->fresh()->total_points;

        // Correct one group result in a new batch and re-approve.
        $fixture = $this->tournament->groupFixtures()->orderBy('match_number')->first();
        $newBatch = $this->openBatch();
        ScoreProposal::create([
            'score_batch_id' => $newBatch->id,
            'fixture_id' => $fixture->id,
            'home_goals' => 5,
            'away_goals' => 5,
            'status' => ProposalStatus::Pending,
        ]);
        $this->resolveProjectedTies($this->tournament, $newBatch);

        $this->actingAs($this->admin())->post(route('manage.scores.approve', $this->tournament));

        $this->assertSame(5, $fixture->fresh()->home_goals);
        // The total is recomputed (not doubled): it changes by at most one fixture's points.
        $this->assertNotSame($firstTotal, $this->entry->fresh()->total_points);
        $this->assertLessThanOrEqual(20, abs($firstTotal - $this->entry->fresh()->total_points));
    }

    public function test_a_knockout_proposal_without_a_winner_is_rejected(): void
    {
        $batch = $this->openBatch();
        $knockout = $this->tournament->fixtures()
            ->whereRelation('phase', 'type', PhaseType::Knockout->value)
            ->orderBy('match_number')
            ->first();

        ScoreProposal::create([
            'score_batch_id' => $batch->id,
            'fixture_id' => $knockout->id,
            'home_goals' => 1,
            'away_goals' => 0,
            'winner_team_id' => null,
            'status' => ProposalStatus::Pending,
        ]);

        $this->actingAs($this->admin())
            ->post(route('manage.scores.approve', $this->tournament))
            ->assertSessionHasErrors('proposals');

        $this->assertNotSame(FixtureStatus::Finished, $knockout->fresh()->status);
        $this->assertSame(BatchStatus::Open, $batch->fresh()->status);
    }

    public function test_a_non_admin_cannot_approve(): void
    {
        $this->proposeAllGroupResults($this->openBatch());

        $this->actingAs(User::factory()->create())
            ->post(route('manage.scores.approve', $this->tournament))
            ->assertForbidden();
    }

    public function test_a_guest_is_redirected_to_login(): void
    {
        $this->post(route('manage.scores.approve', $this->tournament))
            ->assertRedirect(route('login'));
    }

    public function test_approving_a_result_closes_a_still_open_live_scoreboard(): void
    {
        $batch = $this->openBatch();
        $fixture = $this->tournament->groupFixtures()->orderBy('match_number')->first();
        $this->proposeOneGroupFixture($batch, $fixture);

        // The admin forgot to "End match": its live board is still open while the result is approved.
        FixtureLiveState::factory()->for($fixture)->withScore(2, 1)->create();

        $this->actingAs($this->admin())
            ->post(route('manage.scores.approve', $this->tournament))
            ->assertRedirect(route('manage.scores.review', $this->tournament));

        // The official result is published…
        $this->assertSame(FixtureStatus::Finished, $fixture->fresh()->status);

        // …and the stale live board is closed in the same approval, so the Live Center stops
        // treating a finished match as in-play.
        $liveState = $fixture->fresh()->liveState;
        $this->assertSame(LiveStatus::Ended, $liveState->status);
        $this->assertNotNull($liveState->ended_at);
    }

    public function test_approving_a_result_without_a_live_board_succeeds(): void
    {
        $batch = $this->openBatch();
        $fixture = $this->tournament->groupFixtures()->orderBy('match_number')->first();
        $this->proposeOneGroupFixture($batch, $fixture);

        $this->actingAs($this->admin())
            ->post(route('manage.scores.approve', $this->tournament))
            ->assertRedirect(route('manage.scores.review', $this->tournament));

        $this->assertSame(FixtureStatus::Finished, $fixture->fresh()->status);
        $this->assertNull($fixture->fresh()->liveState);
        $this->assertSame(BatchStatus::Approved, $batch->fresh()->status);
    }

    public function test_approving_leaves_an_already_ended_live_board_untouched(): void
    {
        $batch = $this->openBatch();
        $fixture = $this->tournament->groupFixtures()->orderBy('match_number')->first();
        $this->proposeOneGroupFixture($batch, $fixture);

        // The match was already ended normally (EndLiveMatch) an hour ago.
        $endedAt = now()->subHour();
        FixtureLiveState::factory()->for($fixture)->ended()->create(['ended_at' => $endedAt]);

        $this->actingAs($this->admin())->post(route('manage.scores.approve', $this->tournament));

        $liveState = $fixture->fresh()->liveState;
        $this->assertSame(LiveStatus::Ended, $liveState->status);
        // Approval does not re-stamp an already-closed board.
        $this->assertSame($endedAt->toDateTimeString(), $liveState->ended_at->toDateTimeString());
    }

    public function test_a_rejected_proposal_does_not_close_its_fixtures_live_board(): void
    {
        $batch = $this->openBatch();

        // One fixture is published; a different fixture's proposal is rejected while its board is live.
        [$published, $rejectedFixture] = $this->tournament->groupFixtures()
            ->orderBy('match_number')->take(2)->get()->all();
        $this->proposeOneGroupFixture($batch, $published);

        ScoreProposal::create([
            'score_batch_id' => $batch->id,
            'fixture_id' => $rejectedFixture->id,
            'home_goals' => 1,
            'away_goals' => 0,
            'winner_team_id' => $rejectedFixture->home_team_id,
            'status' => ProposalStatus::Rejected,
        ]);
        FixtureLiveState::factory()->for($rejectedFixture)->withScore(1, 0)->create();

        $this->actingAs($this->admin())->post(route('manage.scores.approve', $this->tournament));

        // The rejected fixture is never finished, so its still-open board is left alone.
        $this->assertNotSame(FixtureStatus::Finished, $rejectedFixture->fresh()->status);
        $this->assertSame(LiveStatus::Live, $rejectedFixture->fresh()->liveState->status);
    }

    public function test_ending_an_untouched_live_match_then_approving_publishes_a_zero_zero_result(): void
    {
        $fixture = $this->tournament->groupFixtures()->orderBy('match_number')->first();

        // Reproduce the report: the match went live and was ended without the admin ever touching
        // the score. An untouched board has null goals, which used to yield a null-goal proposal
        // that blocked approval with "Every match in the batch needs a final score."
        $fixture->update(['status' => FixtureStatus::Live]);
        FixtureLiveState::factory()->for($fixture)->create();
        app(EndLiveMatch::class)->end($fixture);

        $this->actingAs($this->admin())
            ->post(route('manage.scores.approve', $this->tournament))
            ->assertSessionHasNoErrors()
            ->assertRedirect(route('manage.scores.review', $this->tournament));

        $fixture->refresh();
        $this->assertSame(FixtureStatus::Finished, $fixture->status);
        $this->assertSame(0, $fixture->home_goals);
        $this->assertSame(0, $fixture->away_goals);
    }

    private function proposeOneGroupFixture(ScoreBatch $batch, Fixture $fixture, int $home = 2, int $away = 1): ScoreProposal
    {
        return ScoreProposal::create([
            'score_batch_id' => $batch->id,
            'fixture_id' => $fixture->id,
            'home_goals' => $home,
            'away_goals' => $away,
            'winner_team_id' => $home > $away ? $fixture->home_team_id : $fixture->away_team_id,
            'status' => ProposalStatus::Pending,
        ]);
    }

    private function admin(): User
    {
        $admin = User::factory()->create();
        config()->set('admin.emails', [$admin->email]);

        return $admin;
    }

    private function openBatch(): ScoreBatch
    {
        return $this->tournament->scoreBatches()->firstOrCreate(['status' => BatchStatus::Open]);
    }

    /**
     * The seeded phased-bracket pool plus a couple of entrants to receive window emails.
     *
     * @return array{0: Pool, 1: list<User>}
     */
    private function phasedPoolWithEntrants(): array
    {
        $phased = $this->tournament->pools()->where('slug', 'world-cup-2026-brothers')->firstOrFail();
        $this->assertSame(ScoringStrategy::PhasedBracket, $phased->scoring_strategy);

        $entrants = [];
        foreach (range(1, 2) as $ignored) {
            $user = User::factory()->create();
            Entry::factory()->for($phased)->for($user)->create();
            $entrants[] = $user;
        }

        return [$phased, $entrants];
    }

    /**
     * Schedule the Round of 32 in the future so it opens (rather than locks) once its participants
     * are projected by an approval.
     */
    private function openRoundOf32After(): void
    {
        $this->tournament->phases()->where('key', PhaseKey::RoundOf32->value)->firstOrFail()
            ->fixtures()->update(['kicks_off_at' => now()->addDays(10)]);
    }

    private function proposeAllGroupResults(ScoreBatch $batch): void
    {
        $rule = $this->seedOrderScores();

        foreach ($this->tournament->groups()->with('teams')->get() as $group) {
            $positions = $group->teams->mapWithKeys(fn ($team) => [$team->id => $team->pivot->position]);

            foreach ($group->fixtures()->get() as $fixture) {
                [$home, $away] = $rule($positions[$fixture->home_team_id], $positions[$fixture->away_team_id]);

                ScoreProposal::create([
                    'score_batch_id' => $batch->id,
                    'fixture_id' => $fixture->id,
                    'home_goals' => $home,
                    'away_goals' => $away,
                    'winner_team_id' => $home > $away ? $fixture->home_team_id : $fixture->away_team_id,
                    'status' => ProposalStatus::Pending,
                ]);
            }
        }
    }
}
