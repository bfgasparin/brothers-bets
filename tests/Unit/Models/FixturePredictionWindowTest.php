<?php

namespace Tests\Unit\Models;

use App\Models\Fixture;
use Illuminate\Support\Carbon;
use Tests\TestCase;

class FixturePredictionWindowTest extends TestCase
{
    private function fixtureKickingOffAt(?Carbon $kickoff): Fixture
    {
        $fixture = new Fixture;
        $fixture->kicks_off_at = $kickoff;

        return $fixture;
    }

    public function test_a_fixture_locks_exactly_at_its_kickoff(): void
    {
        $kickoff = Carbon::parse('2026-06-28 19:00:00', 'UTC');

        $fixture = $this->fixtureKickingOffAt($kickoff);

        // Rolling Predictions locks at kickoff — no buffer.
        $this->assertTrue($kickoff->equalTo($fixture->predictionsLockAt()));
    }

    public function test_it_accepts_predictions_before_kickoff_and_not_after(): void
    {
        $kickoff = Carbon::parse('2026-06-28 19:00:00', 'UTC');
        $fixture = $this->fixtureKickingOffAt($kickoff);

        Carbon::setTestNow($kickoff->copy()->subSecond());
        $this->assertTrue($fixture->acceptsPredictions());

        Carbon::setTestNow($kickoff);
        $this->assertFalse($fixture->acceptsPredictions());

        Carbon::setTestNow($kickoff->copy()->addSecond());
        $this->assertFalse($fixture->acceptsPredictions());

        Carbon::setTestNow();
    }

    public function test_a_fixture_with_no_kickoff_fails_closed(): void
    {
        $fixture = $this->fixtureKickingOffAt(null);

        $this->assertNull($fixture->predictionsLockAt());
        $this->assertFalse($fixture->acceptsPredictions());
    }
}
