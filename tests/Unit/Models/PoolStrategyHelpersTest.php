<?php

namespace Tests\Unit\Models;

use App\Enums\ScoringStrategy;
use App\Models\Pool;
use Tests\TestCase;

class PoolStrategyHelpersTest extends TestCase
{
    private function pool(ScoringStrategy $strategy): Pool
    {
        $pool = new Pool;
        $pool->scoring_strategy = $strategy;

        return $pool;
    }

    public function test_only_the_rolling_strategy_uses_per_match_windows(): void
    {
        $this->assertTrue($this->pool(ScoringStrategy::RollingBracket)->usesPerMatchPredictionWindows());
        $this->assertFalse($this->pool(ScoringStrategy::PhasedBracket)->usesPerMatchPredictionWindows());
        $this->assertFalse($this->pool(ScoringStrategy::UpfrontBracket)->usesPerMatchPredictionWindows());
    }

    public function test_phased_and_rolling_predict_the_official_bracket_but_upfront_does_not(): void
    {
        // "Official bracket" = knockout participants come from real results (no self-derivation).
        $this->assertTrue($this->pool(ScoringStrategy::PhasedBracket)->predictsOfficialBracket());
        $this->assertTrue($this->pool(ScoringStrategy::RollingBracket)->predictsOfficialBracket());
        $this->assertFalse($this->pool(ScoringStrategy::UpfrontBracket)->predictsOfficialBracket());
    }
}
