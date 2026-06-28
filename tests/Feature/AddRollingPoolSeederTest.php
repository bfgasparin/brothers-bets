<?php

namespace Tests\Feature;

use App\Enums\ScoringStrategy;
use App\Models\Entry;
use App\Models\Pool;
use App\Models\Tournament;
use App\Models\User;
use Database\Seeders\AddRollingPoolSeeder;
use Database\Seeders\WorldCup2026Seeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * The targeted production seeder that adds ONLY the Rolling Predictions pool to an existing
 * tournament — the safe alternative to re-running the full WorldCup2026Seeder, which would revert
 * admin edits. This pins its two load-bearing guarantees: it creates the one pool, and it touches
 * nothing else (no deletes, no reverts of tuned pools / rescheduled fixtures / player data).
 */
class AddRollingPoolSeederTest extends TestCase
{
    use RefreshDatabase;

    public function test_it_creates_the_rolling_pool_against_an_existing_tournament(): void
    {
        $this->seed(WorldCup2026Seeder::class);
        // Simulate production seeded BEFORE this change: the rolling pool doesn't exist yet.
        Pool::where('slug', 'world-cup-2026-brothers-2')->delete();
        $this->assertSame(2, Pool::count());

        $this->seed(AddRollingPoolSeeder::class);

        $pool = Pool::where('slug', 'world-cup-2026-brothers-2')->firstOrFail();
        $this->assertSame(ScoringStrategy::RollingBracket, $pool->scoring_strategy);
        $this->assertSame('Bolão dos Brothers 2 - Copa', $pool->name);
        $this->assertSame('30.00', $pool->entry_price);
        $this->assertSame(3, Pool::count());
        // Attached to the existing tournament, not a new one.
        $this->assertSame(1, Tournament::count());
    }

    public function test_it_is_idempotent_and_leaves_existing_data_untouched(): void
    {
        $this->seed(WorldCup2026Seeder::class);
        $tournament = Tournament::firstOrFail();

        // An admin has tuned an existing pool, rescheduled a fixture, and a player has entered.
        $ffa = Pool::where('slug', 'world-cup-2026-ffa')->firstOrFail();
        $ffa->update(['entry_price' => 99.99]);
        $fixture = $tournament->groupFixtures()->orderBy('match_number')->firstOrFail();
        $movedKickoff = now()->addMonths(2)->startOfSecond();
        $fixture->update(['kicks_off_at' => $movedKickoff]);
        $entry = Entry::factory()->for($ffa)->for(User::factory())->create();

        // Run twice — must be a true no-op beyond the rolling pool's own row.
        $this->seed(AddRollingPoolSeeder::class);
        $this->seed(AddRollingPoolSeeder::class);

        // Exactly one rolling pool, no duplicates, no extra pools.
        $this->assertSame(1, Pool::where('slug', 'world-cup-2026-brothers-2')->count());
        $this->assertSame(3, Pool::count());
        // The tuned pool, the reschedule, and the entry all survive untouched.
        $this->assertSame('99.99', $ffa->fresh()->entry_price);
        $this->assertTrue($movedKickoff->equalTo($fixture->fresh()->kicks_off_at));
        $this->assertDatabaseHas('entries', ['id' => $entry->id]);
    }

    public function test_it_no_ops_when_the_tournament_is_missing(): void
    {
        // A fresh database with no World Cup tournament: the seeder attaches to nothing and is safe.
        $this->seed(AddRollingPoolSeeder::class);

        $this->assertSame(0, Pool::count());
    }
}
