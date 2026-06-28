<?php

namespace Tests\Feature;

use App\Models\Entry;
use App\Models\Pool;
use App\Models\Tournament;
use App\Models\User;
use App\Services\Predictions\OfficialBracketProjector;
use Database\Seeders\WorldCup2026Seeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Inertia\Testing\AssertableInertia;
use Tests\Concerns\InteractsWithOfficialResults;
use Tests\Concerns\InteractsWithPredictions;
use Tests\TestCase;

class RollingPredictionControllerTest extends TestCase
{
    use InteractsWithOfficialResults;
    use InteractsWithPredictions;
    use RefreshDatabase;

    private Tournament $tournament;

    private User $user;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed(WorldCup2026Seeder::class);
        $this->tournament = Tournament::firstOrFail();
        $this->user = User::factory()->create();
    }

    private function rollingPool(): Pool
    {
        return Pool::factory()->rollingBracket()->create([
            'tournament_id' => $this->tournament->id,
            'predictions_lock_at' => null,
        ]);
    }

    private function join(Pool $pool): Entry
    {
        return Entry::factory()->for($pool)->for($this->user)->create();
    }

    private function projectRoundOf32(): void
    {
        $this->recordOfficialGroupResults($this->tournament, $this->seedOrderScores());
        (new OfficialBracketProjector)->project($this->tournament);
    }

    public function test_group_save_persists_only_fixtures_still_open(): void
    {
        $pool = $this->rollingPool();
        $this->join($pool);

        $fixtures = $this->tournament->groups()->where('name', 'A')->firstOrFail()
            ->fixtures()->orderBy('match_number')->get();
        $open = $fixtures->first();
        $locked = $fixtures->last();

        $open->update(['kicks_off_at' => now()->addDay()]);
        $locked->update(['kicks_off_at' => now()->subMinute()]);

        // The client may auto-save a whole step in one PUT; a single match can lock mid-batch. The
        // server keeps the still-open fixture and silently drops the one that has kicked off.
        $this->actingAs($this->user)
            ->put(route('pools.predict.group', $pool->slug), ['predictions' => [
                ['fixture_id' => $open->id, 'home_goals' => 2, 'away_goals' => 1],
                ['fixture_id' => $locked->id, 'home_goals' => 3, 'away_goals' => 0],
            ]])
            ->assertRedirect(route('pools.predict.edit', $pool->slug));

        $entry = Entry::where('pool_id', $pool->id)->where('user_id', $this->user->id)->firstOrFail();
        $this->assertDatabaseHas('group_predictions', [
            'entry_id' => $entry->id, 'fixture_id' => $open->id, 'home_goals' => 2, 'away_goals' => 1,
        ]);
        $this->assertDatabaseMissing('group_predictions', [
            'entry_id' => $entry->id, 'fixture_id' => $locked->id,
        ]);
    }

    public function test_group_save_succeeds_even_after_the_first_group_kickoff_has_passed(): void
    {
        $pool = $this->rollingPool();
        $this->join($pool);

        // Every group match but one has already kicked off — the pool-level lock is long past, but a
        // per-match pool must still accept the late match. (A phased/upfront pool would 403 here.)
        $this->tournament->groupFixtures()->update(['kicks_off_at' => now()->subDay()]);
        $open = $this->tournament->groups()->where('name', 'A')->firstOrFail()
            ->fixtures()->orderBy('match_number')->firstOrFail();
        $open->update(['kicks_off_at' => now()->addDay()]);

        $this->actingAs($this->user)
            ->put(route('pools.predict.group', $pool->slug), ['predictions' => [
                ['fixture_id' => $open->id, 'home_goals' => 1, 'away_goals' => 0],
            ]])
            ->assertRedirect(route('pools.predict.edit', $pool->slug));

        $entry = Entry::where('pool_id', $pool->id)->where('user_id', $this->user->id)->firstOrFail();
        $this->assertDatabaseHas('group_predictions', [
            'entry_id' => $entry->id, 'fixture_id' => $open->id,
        ]);
    }

    public function test_knockout_save_persists_an_open_fixture_with_official_teams(): void
    {
        $pool = $this->rollingPool();
        $this->join($pool);
        $this->projectRoundOf32();

        $r32 = $this->knockoutFixture($this->tournament, 'R32-1')->fresh();
        $r32->update(['kicks_off_at' => now()->addDay()]);
        $officialHome = $r32->home_team_id;

        $this->actingAs($this->user)
            ->put(route('pools.predict.knockout', $pool->slug), ['predictions' => [[
                'fixture_id' => $r32->id,
                'home_goals' => 2,
                'away_goals' => 1,
            ]]])
            ->assertRedirect(route('pools.predict.edit', $pool->slug));

        $entry = Entry::where('pool_id', $pool->id)->where('user_id', $this->user->id)->firstOrFail();
        $this->assertDatabaseHas('knockout_predictions', [
            'entry_id' => $entry->id,
            'fixture_id' => $r32->id,
            'home_goals' => 2,
            'away_goals' => 1,
            'advancing_team_id' => $officialHome,
            'predicted_home_team_id' => $officialHome,
            'predicted_away_team_id' => $r32->away_team_id,
        ]);
        $this->assertSame(1, $entry->knockoutPredictions()->count());
    }

    public function test_knockout_save_drops_a_fixture_whose_teams_are_not_known_yet(): void
    {
        $pool = $this->rollingPool();
        $this->join($pool);

        // No rounds projected: the R32 fixture has no participants, so it is still pending.
        $r32 = $this->knockoutFixture($this->tournament, 'R32-1');

        $this->actingAs($this->user)
            ->put(route('pools.predict.knockout', $pool->slug), ['predictions' => [[
                'fixture_id' => $r32->id,
                'home_goals' => 1,
                'away_goals' => 0,
            ]]])
            ->assertRedirect(route('pools.predict.edit', $pool->slug));

        $this->assertDatabaseCount('knockout_predictions', 0);
    }

    public function test_knockout_save_drops_a_fixture_that_has_kicked_off(): void
    {
        $pool = $this->rollingPool();
        $this->join($pool);
        $this->projectRoundOf32();

        $r32 = $this->knockoutFixture($this->tournament, 'R32-1')->fresh();
        $r32->update(['kicks_off_at' => now()->subMinute()]); // already kicked off → locked

        $this->actingAs($this->user)
            ->put(route('pools.predict.knockout', $pool->slug), ['predictions' => [[
                'fixture_id' => $r32->id,
                'home_goals' => 1,
                'away_goals' => 0,
            ]]])
            ->assertRedirect(route('pools.predict.edit', $pool->slug));

        $this->assertDatabaseCount('knockout_predictions', 0);
    }

    public function test_predict_page_carries_a_per_fixture_lock_for_group_and_knockout(): void
    {
        $pool = $this->rollingPool();
        $this->join($pool);
        $this->projectRoundOf32();

        $groupFixture = $this->tournament->groups()->where('name', 'A')->firstOrFail()
            ->fixtures()->orderBy('match_number')->firstOrFail();
        $groupFixture->update(['kicks_off_at' => now()->addDay()]);
        $r32 = $this->knockoutFixture($this->tournament, 'R32-1')->fresh();
        $r32->update(['kicks_off_at' => now()->addDay()]);

        $this->actingAs($this->user)
            ->get(route('pools.predict.edit', $pool->slug))
            ->assertOk()
            ->assertInertia(fn (AssertableInertia $page) => $page
                ->component('pools/predict')
                ->where('pool.scoring_strategy', 'rolling-bracket')
                ->where('groups.0.fixtures.0.predictions_lock_at', $groupFixture->kicks_off_at->toIso8601String())
                ->where('bracket.0.fixtures.0.predictions_lock_at', $r32->kicks_off_at->toIso8601String())
                ->whereNot('bracket.0.fixtures.0.kicks_off_at', null)
            );
    }
}
