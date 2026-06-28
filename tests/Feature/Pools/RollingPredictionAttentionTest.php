<?php

namespace Tests\Feature\Pools;

use App\Models\Entry;
use App\Models\GroupPrediction;
use App\Models\Pool;
use App\Models\Tournament;
use App\Models\User;
use App\Services\Pools\PredictionAttention;
use Database\Seeders\WorldCup2026Seeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Concerns\InteractsWithOfficialResults;
use Tests\Concerns\InteractsWithPredictions;
use Tests\TestCase;

/**
 * Attention for a rolling (per-match) pool counts only matches that are still open AND unpredicted —
 * a match that has kicked off without a pick is not actionable and must not nag the player forever.
 */
class RollingPredictionAttentionTest extends TestCase
{
    use InteractsWithOfficialResults;
    use InteractsWithPredictions;
    use RefreshDatabase;

    private Tournament $tournament;

    private Pool $pool;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed(WorldCup2026Seeder::class);
        $this->tournament = Tournament::firstOrFail();
        $this->pool = Pool::factory()->rollingBracket()->create([
            'tournament_id' => $this->tournament->id,
            'predictions_lock_at' => null,
        ]);
    }

    private function entry(): Entry
    {
        return Entry::factory()->for($this->pool)->for(User::factory())->create();
    }

    public function test_a_rolling_pool_accepts_joins_while_any_match_is_still_open(): void
    {
        // All matches kicked off → joining is closed; reopen one → joinable again (late joiners can
        // still call the remaining games, unlike a single-lock pool).
        $this->tournament->fixtures()->update(['kicks_off_at' => now()->subDay()]);
        $this->assertFalse($this->pool->acceptsJoins());

        $this->tournament->groupFixtures()->orderBy('match_number')->firstOrFail()
            ->update(['kicks_off_at' => now()->addDay()]);
        $this->assertTrue($this->pool->acceptsJoins());
    }

    public function test_an_open_unpredicted_match_wants_attention(): void
    {
        $entry = $this->entry();
        // Every group match is still open (future kickoff), none predicted.
        $this->tournament->groupFixtures()->update(['kicks_off_at' => now()->addDay()]);

        $this->assertTrue((new PredictionAttention)->needsAttention($this->pool, $entry));
    }

    public function test_a_match_that_kicked_off_unpredicted_does_not_nag(): void
    {
        $entry = $this->entry();

        // All group matches have kicked off (locked) except none are predicted: nothing is actionable.
        $this->tournament->groupFixtures()->update(['kicks_off_at' => now()->subMinute()]);

        $this->assertFalse((new PredictionAttention)->needsAttention($this->pool, $entry));
    }

    public function test_attention_clears_once_every_open_match_is_predicted(): void
    {
        $entry = $this->entry();

        // One match open, the rest already kicked off (and so not actionable).
        $this->tournament->groupFixtures()->update(['kicks_off_at' => now()->subMinute()]);
        $open = $this->tournament->groups()->where('name', 'A')->firstOrFail()
            ->fixtures()->orderBy('match_number')->firstOrFail();
        $open->update(['kicks_off_at' => now()->addDay()]);

        $this->assertTrue((new PredictionAttention)->needsAttention($this->pool, $entry));

        GroupPrediction::factory()->create([
            'entry_id' => $entry->id,
            'fixture_id' => $open->id,
            'home_goals' => 1,
            'away_goals' => 0,
        ]);

        $this->assertFalse((new PredictionAttention)->needsAttention($this->pool, $entry->fresh()));
    }
}
