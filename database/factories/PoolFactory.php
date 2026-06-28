<?php

namespace Database\Factories;

use App\Enums\PoolAccent;
use App\Enums\ScoringStrategy;
use App\Models\Pool;
use App\Models\Tournament;
use Database\Seeders\WorldCup2026Seeder;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Str;

/**
 * @extends Factory<Pool>
 */
class PoolFactory extends Factory
{
    /**
     * Cycles distinct accents across the pools a factory builds, so sibling pools over one
     * tournament read as distinct rather than collapsing to a single colour.
     */
    private static int $accentSequence = 0;

    /**
     * Define the model's default state.
     *
     * The scoring_config below is duplicated verbatim in {@see WorldCup2026Seeder} —
     * keep them in sync.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        $name = fake()->unique()->words(3, true).' Cup';

        return [
            'tournament_id' => Tournament::factory(),
            'slug' => Str::slug($name),
            'name' => Str::title($name),
            'source' => fake()->company(),
            'accent' => PoolAccent::forIndex(self::$accentSequence++),
            'scoring_strategy' => ScoringStrategy::UpfrontBracket,
            'scoring_config' => [
                'group' => [
                    'exact_score' => 20,
                    'winner_and_one_team_exact_goals' => 15,
                    'correct_outcome_wrong_goals' => 10,
                    'one_team_exact_goals_wrong_outcome' => 5,
                ],
                'knockout' => [
                    'correct_team' => 10,
                    'team_goal_count_bonus' => 5,
                    'champion' => 30,
                ],
            ],
            'predictions_lock_at' => fake()->dateTimeBetween('+1 week', '+1 month'),
            'entry_price' => fake()->randomElement([20, 30, 40, 50, 100]),
            'currency' => 'BRL',
            'house_fee_percentage' => fake()->randomElement([0, 10, 15, 20]),
            'prize_structure' => [
                ['place' => 1, 'percentage' => 70],
                ['place' => 2, 'percentage' => 20],
                ['place' => 3, 'percentage' => 10],
            ],
        ];
    }

    /**
     * The phased-bracket strategy: the group stage is predicted upfront, each knockout round is
     * predicted against the official match-ups once known, and scores carry rising round
     * multipliers. The scoring_config below is duplicated verbatim in {@see WorldCup2026Seeder}
     * for the phased "Bolão dos Brothers - Copa" pool — keep them in sync.
     */
    public function phasedBracket(): static
    {
        return $this->state(fn (): array => [
            'scoring_strategy' => ScoringStrategy::PhasedBracket,
            'scoring_config' => self::phasedScoringConfig(),
        ]);
    }

    /**
     * The rolling ("per-match") strategy: every match is predicted on its own clock, staying open
     * until its kickoff. Scoring is identical to the phased pool (scoreline tiers, rising round
     * multipliers, advancing bonus), so its scoring_config matches {@see phasedBracket()} verbatim;
     * only the per-fixture lock differs. Kept in sync with the rolling pool in {@see WorldCup2026Seeder}.
     */
    public function rollingBracket(): static
    {
        return $this->state(fn (): array => [
            'scoring_strategy' => ScoringStrategy::RollingBracket,
            'scoring_config' => self::phasedScoringConfig(),
        ]);
    }

    /**
     * The scoring_config shared by the phased and rolling strategies. Duplicated verbatim in
     * {@see WorldCup2026Seeder} for the corresponding seeded pools — keep them in sync.
     *
     * @return array<string, mixed>
     */
    private static function phasedScoringConfig(): array
    {
        return [
            'group' => [
                'exact_score' => 20,
                'winner_and_one_team_exact_goals' => 15,
                'correct_outcome_wrong_goals' => 10,
                'one_team_exact_goals_wrong_outcome' => 5,
            ],
            'knockout' => [
                'exact_score' => 20,
                'winner_and_one_team_exact_goals' => 15,
                'correct_outcome_wrong_goals' => 10,
                'one_team_exact_goals_wrong_outcome' => 5,
                'advancing_team' => 10,
                'round_multipliers' => [
                    'round_of_32' => 1,
                    'round_of_16' => 2,
                    'quarter_finals' => 4,
                    'semi_finals' => 6,
                    'third_place' => 4,
                    'final' => 8,
                ],
            ],
        ];
    }
}
