<?php

namespace Tests\Feature\Scoring;

use App\Models\Entry;
use App\Models\Fixture;
use App\Models\KnockoutPrediction;
use App\Models\Pool;
use App\Models\Tournament;
use App\Models\User;
use App\Services\Predictions\OfficialBracketProjector;
use App\Services\Scoring\ScoreEngine;
use Database\Seeders\WorldCup2026Seeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Concerns\InteractsWithOfficialResults;
use Tests\Concerns\InteractsWithPredictions;
use Tests\TestCase;

/**
 * A rolling (per-match) pool locks each match at its own kickoff but scores IDENTICALLY to the
 * phased pool — scoreline tiers, rising round multipliers, and the flat advancing bonus, no champion
 * bonus. This mirrors {@see PhasedPoolScoringTest} to prove the scoring rules are reused unchanged.
 */
class RollingPoolScoringTest extends TestCase
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
        $this->pool = Pool::factory()->rollingBracket()->create([
            'tournament_id' => $this->tournament->id,
        ]);
        $this->entry = Entry::factory()->for($this->pool)->for(User::factory())->create();
    }

    public function test_rolling_pool_scores_exactly_like_the_phased_pool(): void
    {
        $this->recordOfficialGroupResults($this->tournament, $this->seedOrderScores());
        $this->advanceOfficialHome($this->tournament, new OfficialBracketProjector);

        $r32 = $this->knockoutFixture($this->tournament, 'R32-1')->fresh();
        $final = $this->knockoutFixture($this->tournament, 'F')->fresh();

        $this->predictKnockout($r32, $r32->home_team_id);
        $this->predictKnockout($final, $final->home_team_id);

        (new ScoreEngine)->recompute($this->pool);

        // R32 (×1): exact 20 + advancing 10 = 30.
        $this->assertSame(30, $this->pointsFor($r32->id));
        // Final (×8): exact 20 × 8 = 160 + advancing 10 = 170. No champion bonus.
        $this->assertSame(170, $this->pointsFor($final->id));
        $this->assertSame(200, $this->entry->fresh()->total_points);
    }

    private function predictKnockout(Fixture $fixture, int $advancingTeamId): void
    {
        KnockoutPrediction::create([
            'entry_id' => $this->entry->id,
            'fixture_id' => $fixture->id,
            'predicted_home_team_id' => $fixture->home_team_id,
            'predicted_away_team_id' => $fixture->away_team_id,
            'home_goals' => 1,
            'away_goals' => 0,
            'advancing_team_id' => $advancingTeamId,
        ]);
    }

    private function pointsFor(int $fixtureId): ?int
    {
        return $this->entry->knockoutPredictions()->where('fixture_id', $fixtureId)->value('points_awarded');
    }
}
