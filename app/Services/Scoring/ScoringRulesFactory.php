<?php

namespace App\Services\Scoring;

use App\Enums\ScoringStrategy;
use App\Services\Scoring\Strategies\PhasedBracketRules;
use App\Services\Scoring\Strategies\ScoringRules;
use App\Services\Scoring\Strategies\UpfrontBracketRules;

/**
 * Resolves the {@see ScoringRules} implementation for a tournament's scoring strategy. Adding a
 * new strategy (e.g. a between-phases tournament whose knockouts score like the group stage) is
 * a single new match arm plus its rules class — the engine itself never changes.
 */
class ScoringRulesFactory
{
    public function make(ScoringStrategy $strategy): ScoringRules
    {
        return match ($strategy) {
            ScoringStrategy::UpfrontBracket => app(UpfrontBracketRules::class),
            // Rolling Predictions locks per match but scores exactly like the phased pool.
            ScoringStrategy::PhasedBracket, ScoringStrategy::RollingBracket => app(PhasedBracketRules::class),
        };
    }
}
