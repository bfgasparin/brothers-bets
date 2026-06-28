<?php

namespace Tests\Feature\Predictions;

use App\Models\Entry;
use App\Models\GroupPrediction;
use App\Models\Pool;
use App\Models\Tournament;
use App\Models\User;
use App\Services\Predictions\PredictionImporter;
use Database\Seeders\WorldCup2026Seeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Importing into a rolling (per-match) pool must clean-replace ONLY the matches still open — a match
 * that has already kicked off (and may be scored) must survive untouched, even though the group
 * window as a whole is still open for the late matches.
 */
class RollingPredictionImportTest extends TestCase
{
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

    private function rollingPool(string $slug): Pool
    {
        return Pool::factory()->rollingBracket()->create([
            'tournament_id' => $this->tournament->id,
            'slug' => $slug,
            'predictions_lock_at' => null,
        ]);
    }

    public function test_import_only_replaces_matches_still_open(): void
    {
        $source = $this->rollingPool('rolling-source');
        $destination = $this->rollingPool('rolling-destination');
        $sourceEntry = Entry::factory()->for($source)->for($this->user)->create();
        $destinationEntry = Entry::factory()->for($destination)->for($this->user)->create();

        // Lock the whole stage, then reopen one match.
        $this->tournament->groupFixtures()->update(['kicks_off_at' => now()->subDay()]);
        $fixtures = $this->tournament->groups()->where('name', 'A')->firstOrFail()
            ->fixtures()->orderBy('match_number')->get();
        $locked = $fixtures->first();
        $open = $fixtures->get(1);
        $open->update(['kicks_off_at' => now()->addDay()]);

        // The destination already scored the locked match; the source has a pick for the open match.
        GroupPrediction::create([
            'entry_id' => $destinationEntry->id, 'fixture_id' => $locked->id, 'home_goals' => 5, 'away_goals' => 5,
        ]);
        GroupPrediction::create([
            'entry_id' => $sourceEntry->id, 'fixture_id' => $open->id, 'home_goals' => 2, 'away_goals' => 1,
        ]);

        (new PredictionImporter)->import($destinationEntry, $source);

        // The locked (scored) destination pick survives untouched…
        $this->assertDatabaseHas('group_predictions', [
            'entry_id' => $destinationEntry->id, 'fixture_id' => $locked->id, 'home_goals' => 5, 'away_goals' => 5,
        ]);
        // …and the still-open match is filled from the source.
        $this->assertDatabaseHas('group_predictions', [
            'entry_id' => $destinationEntry->id, 'fixture_id' => $open->id, 'home_goals' => 2, 'away_goals' => 1,
        ]);
    }
}
