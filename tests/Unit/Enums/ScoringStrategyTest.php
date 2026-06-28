<?php

namespace Tests\Unit\Enums;

use App\Enums\ScoringStrategy;
use Tests\TestCase;

class ScoringStrategyTest extends TestCase
{
    /**
     * Every case must answer the display helpers — the exhaustive match arms throw an
     * UnhandledMatchError if a new case is added without copy, so this guards every strategy.
     */
    public function test_every_strategy_supplies_display_copy(): void
    {
        foreach (ScoringStrategy::cases() as $strategy) {
            $this->assertNotEmpty($strategy->label());
            $this->assertNotEmpty($strategy->description());

            $howToPlay = $strategy->howToPlay();
            $this->assertNotEmpty($howToPlay['summary']);
            $this->assertNotEmpty($howToPlay['steps']);
        }
    }

    public function test_rolling_bracket_is_a_distinct_case(): void
    {
        $this->assertSame('rolling-bracket', ScoringStrategy::RollingBracket->value);
    }
}
