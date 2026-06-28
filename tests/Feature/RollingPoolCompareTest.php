<?php

namespace Tests\Feature;

use App\Models\Entry;
use App\Models\GroupPrediction;
use App\Models\Pool;
use App\Models\Tournament;
use App\Models\User;
use Database\Seeders\WorldCup2026Seeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Inertia\Testing\AssertableInertia;
use Tests\TestCase;

/**
 * The compare anti-cheat gate for a rolling (per-match) pool: reveal is per fixture. A match that has
 * kicked off exposes opponents' picks; a still-open sibling in the same group stays hidden. Getting
 * this wrong would leak a pick the opponent can still edit.
 */
class RollingPoolCompareTest extends TestCase
{
    use RefreshDatabase;

    private Tournament $tournament;

    private Pool $pool;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed(WorldCup2026Seeder::class);
        $this->tournament = Tournament::firstOrFail();
        // A distinct slug from the seeded rolling pool (world-cup-2026-brothers-2) to avoid a collision.
        $this->pool = Pool::factory()->rollingBracket()->create([
            'tournament_id' => $this->tournament->id,
            'slug' => 'rolling-compare',
            'predictions_lock_at' => null,
        ]);
    }

    public function test_reveal_is_per_fixture_for_a_rolling_pool(): void
    {
        $viewer = User::factory()->create();
        Entry::factory()->for($this->pool)->for($viewer)->create();
        $opponent = Entry::factory()->for($this->pool)->for(User::factory()->create(['name' => 'Rival']))->create();

        $fixtures = $this->tournament->groups()->where('name', 'A')->firstOrFail()
            ->fixtures()->orderBy('match_number')->get();
        $kickedOff = $fixtures->first();
        $stillOpen = $fixtures->get(1);

        $kickedOff->update(['kicks_off_at' => now()->subMinute()]);
        $stillOpen->update(['kicks_off_at' => now()->addDay()]);

        foreach ([$kickedOff, $stillOpen] as $fixture) {
            GroupPrediction::factory()->create([
                'entry_id' => $opponent->id,
                'fixture_id' => $fixture->id,
                'home_goals' => 3,
                'away_goals' => 1,
            ]);
        }

        $this->actingAs($viewer)
            ->get(route('pools.show', ['pool' => $this->pool->slug, 'compare' => (string) $opponent->id]))
            ->assertOk()
            ->assertInertia(fn (AssertableInertia $page) => $page
                // The match that has kicked off reveals the opponent's pick…
                ->where("comparison.players.1.group_predictions.{$kickedOff->id}.home_goals", 3)
                // …while the still-open sibling stays hidden.
                ->missing("comparison.players.1.group_predictions.{$stillOpen->id}")
            );
    }
}
