<?php

namespace Tests\Unit\Services\Scoring;

use App\Enums\ScoringStrategy;
use App\Services\Scoring\ScoringRulesFactory;
use App\Services\Scoring\Strategies\PhasedBracketRules;
use App\Services\Scoring\Strategies\UpfrontBracketRules;
use Tests\TestCase;

class ScoringRulesFactoryTest extends TestCase
{
    public function test_it_resolves_the_upfront_bracket_rules(): void
    {
        $factory = new ScoringRulesFactory;

        $this->assertInstanceOf(
            UpfrontBracketRules::class,
            $factory->make(ScoringStrategy::UpfrontBracket),
        );
    }

    public function test_it_resolves_the_phased_bracket_rules(): void
    {
        $factory = new ScoringRulesFactory;

        $this->assertInstanceOf(
            PhasedBracketRules::class,
            $factory->make(ScoringStrategy::PhasedBracket),
        );
    }

    public function test_it_scores_the_rolling_bracket_with_the_phased_rules(): void
    {
        $factory = new ScoringRulesFactory;

        // Rolling Predictions locks per match but scores identically to the phased pool:
        // scoreline tiers, rising round multipliers, and the flat advancing bonus.
        $this->assertInstanceOf(
            PhasedBracketRules::class,
            $factory->make(ScoringStrategy::RollingBracket),
        );
    }
}
