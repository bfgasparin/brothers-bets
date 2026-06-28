<?php

namespace Tests\Feature\Manage;

use App\Enums\OrderingScope;
use App\Models\Fixture;
use App\Models\Pool;
use App\Models\Team;
use App\Models\Tournament;
use App\Models\User;
use App\Notifications\PredictionsOverwrittenNotification;
use App\Services\Predictions\BracketResolver;
use Database\Seeders\WorldCup2026Seeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Notification;
use Inertia\Testing\AssertableInertia;
use Tests\Concerns\InteractsWithOfficialResults;
use Tests\Concerns\InteractsWithPredictions;
use Tests\TestCase;

class EntryImportControllerTest extends TestCase
{
    use InteractsWithOfficialResults;
    use InteractsWithPredictions;
    use RefreshDatabase;

    private Tournament $tournament;

    private Pool $pool;

    protected function setUp(): void
    {
        parent::setUp();

        $this->withoutVite();
        $this->seed(WorldCup2026Seeder::class);
        $this->tournament = Tournament::firstOrFail();
        $this->pool = $this->tournament->pools()->where('slug', 'world-cup-2026-ffa')->firstOrFail();
    }

    public function test_non_admins_cannot_access_any_backfill_endpoint(): void
    {
        $this->actingAs(User::factory()->create())
            ->get(route('manage.backfill.create', $this->tournament))
            ->assertForbidden();

        $this->actingAs(User::factory()->create())
            ->post(route('manage.backfill.preview', $this->tournament))
            ->assertForbidden();

        $this->actingAs(User::factory()->create())
            ->post(route('manage.backfill.commit', $this->tournament))
            ->assertForbidden();
    }

    public function test_the_create_screen_lists_all_pools(): void
    {
        // The upfront, phased, and rolling pools of the tournament are all offered.
        $this->actingAs($this->admin())
            ->get(route('manage.backfill.create', $this->tournament))
            ->assertInertia(fn (AssertableInertia $page) => $page
                ->component('manage/backfill')
                ->has('pools', 3)
                ->has('pools.0.scoring_label')
                ->has('users')
                ->has('users.0.avatar')
            );
    }

    public function test_preview_auto_creates_the_entry_without_notifying_and_renders_flags(): void
    {
        Notification::fake();
        $user = User::factory()->create();

        $this->actingAs($this->admin())
            ->post(route('manage.backfill.preview', $this->tournament), [
                'pool_id' => $this->pool->id,
                'user_id' => $user->id,
                'json' => json_encode($this->groupBlob()),
            ])
            ->assertInertia(fn (AssertableInertia $page) => $page
                ->component('manage/backfill-review')
                ->where('preview.has_errors', false)
                ->where('user.id', $user->id)
                ->has('preview.rows')
                // The upfront pool derives a bracket, so authored mode is on offer (gates the
                // "Import the bracket as authored" button on the review screen).
                ->where('preview.can_author', true)
            );

        $this->assertDatabaseHas('entries', ['pool_id' => $this->pool->id, 'user_id' => $user->id]);
        Notification::assertNothingSent();

        // The preview must persist no predictions.
        $entry = $this->pool->entryFor($user);
        $this->assertSame(0, $entry->groupPredictions()->count());
    }

    public function test_preview_accepts_a_phased_pool(): void
    {
        $phased = $this->tournament->pools()->where('slug', 'world-cup-2026-brothers')->firstOrFail();

        $this->actingAs($this->admin())
            ->post(route('manage.backfill.preview', $this->tournament), [
                'pool_id' => $phased->id,
                'user_id' => User::factory()->create()->id,
                'json' => json_encode($this->groupBlob()),
            ])
            ->assertInertia(fn (AssertableInertia $page) => $page
                ->component('manage/backfill-review')
                ->where('preview.has_errors', false)
                // A phased pool derives no bracket, so authored mode is not applicable.
                ->where('preview.can_author', false)
            );
    }

    public function test_preview_rejects_a_pool_from_another_tournament(): void
    {
        $otherPool = Pool::factory()->create();

        $this->actingAs($this->admin())
            ->post(route('manage.backfill.preview', $this->tournament), [
                'pool_id' => $otherPool->id,
                'user_id' => User::factory()->create()->id,
                'json' => json_encode($this->groupBlob()),
            ])
            ->assertSessionHasErrors('pool_id');
    }

    public function test_commit_writes_predictions_and_re_scores_the_pool(): void
    {
        Notification::fake();
        $this->recordOfficialGroupResults($this->tournament, $this->seedOrderScores());

        $user = User::factory()->create();

        $this->actingAs($this->admin())
            ->post(route('manage.backfill.commit', $this->tournament), $this->groupCommitPayload($user))
            ->assertRedirect(route('manage.backfill.create', $this->tournament));

        $entry = $this->pool->entryFor($user);
        $this->assertSame(72, $entry->groupPredictions()->count());
        $this->assertNotNull($entry->refresh()->total_points);
        $this->assertGreaterThan(0, $entry->total_points);

        // A first-time backfill into an empty entry stays silent — nothing was overwritten.
        Notification::assertNotSentTo($user, PredictionsOverwrittenNotification::class);
    }

    public function test_commit_is_blocked_for_a_populated_entry_without_overwrite(): void
    {
        $user = User::factory()->create();
        $entry = $this->pool->entries()->create(['user_id' => $user->id]);
        $this->predictGroup($entry, $this->tournament, 'A', $this->seedOrderScores());

        $payload = $this->groupCommitPayload($user);

        $this->actingAs($this->admin())
            ->post(route('manage.backfill.commit', $this->tournament), $payload)
            ->assertSessionHasErrors('overwrite');

        // Only the 6 fixtures of group A remain — nothing was overwritten.
        $this->assertSame(6, $entry->groupPredictions()->count());
    }

    public function test_commit_overwrites_a_populated_entry_when_confirmed_and_notifies_the_player(): void
    {
        Notification::fake();
        $user = User::factory()->create();
        $entry = $this->pool->entries()->create(['user_id' => $user->id]);
        $this->predictGroup($entry, $this->tournament, 'A', $this->seedOrderScores());

        $payload = $this->groupCommitPayload($user);
        $payload['overwrite'] = true;

        $this->actingAs($this->admin())
            ->post(route('manage.backfill.commit', $this->tournament), $payload)
            ->assertRedirect();

        $this->assertSame(72, $entry->groupPredictions()->count());

        // The player whose picks were replaced is told an organizer changed them.
        Notification::assertSentTo(
            $user,
            PredictionsOverwrittenNotification::class,
            fn (PredictionsOverwrittenNotification $notification): bool => $notification->pool->is($this->pool),
        );
    }

    public function test_the_overwrite_email_renders_with_the_pool_identity(): void
    {
        // Notification::fake skips rendering, so compile the branded template directly to catch
        // template/route/accent errors and confirm it leads with the pool name + source.
        $mail = (new PredictionsOverwrittenNotification($this->pool))->toMail(User::factory()->create());
        $html = view($mail->view[0], $mail->viewData)->render();

        // Blade escapes the names (the pool name carries an "&"), so compare the escaped form.
        $this->assertStringContainsString(e($this->pool->name), $html);
        $this->assertStringContainsString(e($this->pool->source), $html);
    }

    public function test_preview_reports_a_group_tie_resolved_by_the_pasted_standings(): void
    {
        $byPosition = $this->groupATeamsByPosition();
        [$matches] = $this->tieGroupRows();

        $json = [
            'matches' => $matches,
            'group_standings' => [[
                'group' => 'A',
                'standings' => [$byPosition[2]->code, $byPosition[1]->code, $byPosition[3]->code, $byPosition[4]->code],
            ]],
        ];

        $this->actingAs($this->admin())
            ->post(route('manage.backfill.preview', $this->tournament), [
                'pool_id' => $this->pool->id,
                'user_id' => User::factory()->create()->id,
                'json' => json_encode($json),
            ])
            ->assertInertia(fn (AssertableInertia $page) => $page
                ->component('manage/backfill-review')
                ->where('preview.has_errors', false)
                ->where('group_standings_team_ids.A', [$byPosition[2]->id, $byPosition[1]->id, $byPosition[3]->id, $byPosition[4]->id])
                ->has('preview.group_ties', 1)
                ->where('preview.group_ties.0.group', 'A')
                ->where('preview.group_ties.0.resolved_by_standings', true)
            );
    }

    public function test_commit_applies_group_standings_team_ids_to_break_a_tie(): void
    {
        Notification::fake();
        $byPosition = $this->groupATeamsByPosition();
        [, $group] = $this->tieGroupRows();
        $user = User::factory()->create();

        $this->actingAs($this->admin())
            ->post(route('manage.backfill.commit', $this->tournament), [
                'pool_id' => $this->pool->id,
                'user_id' => $user->id,
                'group' => $group,
                'knockout' => [],
                'thirds_team_ids' => [],
                'group_standings_team_ids' => ['A' => [$byPosition[2]->id, $byPosition[1]->id]],
            ])
            ->assertRedirect(route('manage.backfill.create', $this->tournament));

        $entry = $this->pool->entryFor($user);
        $groupA = $this->tournament->groups()->where('name', 'A')->firstOrFail();
        $row = $entry->groupOrderings()
            ->where('scope', OrderingScope::WithinGroup)
            ->where('group_id', $groupA->id)
            ->firstOrFail();

        $this->assertSame([$byPosition[2]->id, $byPosition[1]->id], array_map('intval', $row->ordered_team_ids));
    }

    public function test_preview_in_authored_mode_marks_the_payload_literal(): void
    {
        $this->actingAs($this->admin())
            ->post(route('manage.backfill.preview', $this->tournament), [
                'pool_id' => $this->pool->id,
                'user_id' => User::factory()->create()->id,
                'json' => json_encode($this->groupBlob()),
                'literal' => true,
            ])
            ->assertInertia(fn (AssertableInertia $page) => $page
                ->component('manage/backfill-review')
                ->where('preview.literal', true)
                ->where('preview.has_errors', false)
                ->has('json')
            );
    }

    public function test_commit_in_authored_mode_stores_the_authored_bracket_and_marks_the_entry(): void
    {
        Notification::fake();
        $user = User::factory()->create();

        // The author's own knockout participants for one fixture — teams the FIFA derivation would
        // never put there. Authored mode stores them verbatim.
        $fixture = $this->tournament->knockoutFixtures()->orderBy('match_number')->firstOrFail();
        $teams = Team::where('is_placeholder', false)->orderBy('id')->take(2)->get();

        $payload = $this->groupCommitPayload($user);
        $payload['literal'] = true;
        $payload['knockout'] = [[
            'fixture_id' => $fixture->id,
            'home_goals' => 2,
            'away_goals' => 1,
            'advancing_team_id' => $teams[0]->id,
            'predicted_home_team_id' => $teams[0]->id,
            'predicted_away_team_id' => $teams[1]->id,
        ]];

        $this->actingAs($this->admin())
            ->post(route('manage.backfill.commit', $this->tournament), $payload)
            ->assertRedirect(route('manage.backfill.create', $this->tournament));

        $entry = $this->pool->entryFor($user);
        $this->assertNotNull($entry->authored_bracket_at, 'The entry is marked as an authored bracket.');

        $pred = $entry->knockoutPredictions()->where('fixture_id', $fixture->id)->firstOrFail();
        $this->assertSame($teams[0]->id, $pred->predicted_home_team_id);
        $this->assertSame($teams[1]->id, $pred->predicted_away_team_id);
        $this->assertSame($teams[0]->id, $pred->advancing_team_id, 'A 2-1 home score advances the authored home team.');
        // Only the one authored knockout row is stored — derive mode would have filled all 32.
        $this->assertSame(1, $entry->knockoutPredictions()->count());
    }

    public function test_an_authored_bracket_blocks_the_prediction_page_without_re_deriving(): void
    {
        // Build a real derived bracket, then deliberately plant an out-of-place team in one knockout
        // slot and mark the entry as authored — exactly the state an authored backfill leaves.
        $user = User::factory()->create();
        $entry = $this->pool->entries()->create(['user_id' => $user->id]);
        $this->predictAllGroups($entry, $this->tournament, $this->seedOrderScores());
        (new BracketResolver)->persist($entry);

        $pred = $entry->knockoutPredictions()
            ->whereNotNull('predicted_home_team_id')
            ->whereNotNull('predicted_away_team_id')
            ->firstOrFail();
        $stranger = Team::where('is_placeholder', false)
            ->whereNotIn('id', [$pred->predicted_home_team_id, $pred->predicted_away_team_id])
            ->firstOrFail();

        $pred->update([
            'predicted_home_team_id' => $stranger->id,
            'advancing_team_id' => $stranger->id,
            'home_goals' => 1,
            'away_goals' => 0,
        ]);
        $entry->authored_bracket_at = now();
        $entry->save();

        // Opening the wizard would re-derive (and wipe the stranger) — instead it must redirect.
        $this->actingAs($user)
            ->get(route('pools.predict.edit', $this->pool))
            ->assertRedirect(route('pools.show', $this->pool));

        $this->assertSame(
            $stranger->id,
            $entry->knockoutPredictions()->where('fixture_id', $pred->fixture_id)->value('predicted_home_team_id'),
            'The page load must not re-derive an authored bracket.',
        );
    }

    public function test_the_create_screen_badges_users_with_an_authored_import(): void
    {
        $authored = User::factory()->create();
        $entry = $this->pool->entries()->create(['user_id' => $authored->id]);
        $entry->authored_bracket_at = now();
        $entry->save();

        $plain = User::factory()->create();

        $this->actingAs($this->admin())
            ->get(route('manage.backfill.create', $this->tournament))
            ->assertInertia(fn (AssertableInertia $page) => $page
                ->component('manage/backfill')
                ->where('users', fn ($users): bool => collect($users)->firstWhere('id', $authored->id)['has_authored_import'] === true
                    && collect($users)->firstWhere('id', $plain->id)['has_authored_import'] === false)
            );
    }

    /**
     * A 72-row group stage with group A scored to a perfect 1st/2nd tie (drawn head-to-head, both beat
     * 3rd & 4th 1–0) and every other group in seed order. Returns the pasted-JSON matches and the
     * commit `group` rows in lockstep.
     *
     * @return array{0: list<array<string, mixed>>, 1: list<array<string, int>>}
     */
    private function tieGroupRows(): array
    {
        $seed = $this->seedOrderScores();
        $tie = function (int $homePosition, int $awayPosition): array {
            if (min($homePosition, $awayPosition) === 1 && max($homePosition, $awayPosition) === 2) {
                return [1, 1];
            }

            return $homePosition < $awayPosition ? [1, 0] : [0, 1];
        };

        $matches = [];
        $group = [];

        foreach ($this->tournament->groups()->orderBy('sort_order')->get() as $g) {
            $positions = $g->teams()->get()->mapWithKeys(fn (Team $team): array => [$team->id => $team->pivot->position]);
            $rule = $g->name === 'A' ? $tie : $seed;

            foreach ($g->fixtures()->with(['homeTeam', 'awayTeam'])->orderBy('match_number')->get() as $fixture) {
                [$home, $away] = $rule($positions[$fixture->home_team_id], $positions[$fixture->away_team_id]);

                $matches[] = [
                    'match_number' => $fixture->match_number,
                    'home_team' => $fixture->homeTeam->code,
                    'away_team' => $fixture->awayTeam->code,
                    'home_goals' => $home,
                    'away_goals' => $away,
                ];
                $group[] = ['fixture_id' => $fixture->id, 'home_goals' => $home, 'away_goals' => $away];
            }
        }

        return [$matches, $group];
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

    /**
     * A group-only backfill blob (the importer derives the knockout bracket), built from the seeded
     * fixtures with a seed-order scoreline.
     *
     * @return array<string, mixed>
     */
    private function groupBlob(): array
    {
        $matches = [];

        foreach ($this->groupFixtures() as $fixture) {
            [$home, $away] = $this->seedOrderScores()(
                $fixture->positions['home'],
                $fixture->positions['away'],
            );

            $matches[] = [
                'match_number' => $fixture->match_number,
                'home_team' => $fixture->homeTeam->code,
                'away_team' => $fixture->awayTeam->code,
                'home_goals' => $home,
                'away_goals' => $away,
            ];
        }

        return ['matches' => $matches];
    }

    /**
     * The commit POST body for a group-only backfill.
     *
     * @return array<string, mixed>
     */
    private function groupCommitPayload(User $user): array
    {
        $group = [];

        foreach ($this->groupFixtures() as $fixture) {
            [$home, $away] = $this->seedOrderScores()(
                $fixture->positions['home'],
                $fixture->positions['away'],
            );

            $group[] = ['fixture_id' => $fixture->id, 'home_goals' => $home, 'away_goals' => $away];
        }

        return [
            'pool_id' => $this->pool->id,
            'user_id' => $user->id,
            'group' => $group,
            'knockout' => [],
            'thirds_team_ids' => [],
        ];
    }

    /**
     * The tournament's group fixtures, each decorated with its two teams' seed positions.
     *
     * @return Collection<int, Fixture>
     */
    private function groupFixtures(): Collection
    {
        $positions = [];
        foreach ($this->tournament->groups()->with('teams')->get() as $group) {
            foreach ($group->teams as $team) {
                $positions[$team->id] = $team->pivot->position;
            }
        }

        return $this->tournament->groupFixtures()->with(['homeTeam', 'awayTeam'])->orderBy('match_number')->get()
            ->each(function ($fixture) use ($positions): void {
                $fixture->positions = [
                    'home' => $positions[$fixture->home_team_id],
                    'away' => $positions[$fixture->away_team_id],
                ];
            });
    }

    private function admin(): User
    {
        $admin = User::factory()->create();
        config()->set('admin.emails', [$admin->email]);

        return $admin;
    }
}
