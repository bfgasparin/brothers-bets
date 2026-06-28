<?php

namespace Database\Seeders;

use App\Enums\FeederOutcome;
use App\Enums\PhaseKey;
use App\Enums\PhaseType;
use App\Enums\PoolAccent;
use App\Enums\ScoringStrategy;
use App\Enums\Sport;
use App\Enums\TournamentStatus;
use App\Models\Fixture;
use App\Models\Group;
use App\Models\Phase;
use App\Models\Pool;
use App\Models\Team;
use App\Models\Tournament;
use App\Services\Predictions\ThirdPlaceAllocation;
use Database\Factories\PoolFactory;
use Illuminate\Database\Seeder;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;

class WorldCup2026Seeder extends Seeder
{
    /**
     * The 12 groups (A–L) from the official FIFA World Cup 2026 final draw (Washington D.C.,
     * December 5, 2025), each with its four teams in seeded position order (1 = host/top seed).
     * Hosts are fixed: Mexico A1, Canada B1, United States D1.
     *
     * @var array<string, list<array{name: string, code: ?string, placeholder?: bool}>>
     */
    private const GROUPS = [
        'A' => [['name' => 'Mexico', 'code' => 'MEX'], ['name' => 'South Korea', 'code' => 'KOR'], ['name' => 'South Africa', 'code' => 'RSA'], ['name' => 'Czechia', 'code' => 'CZE']],
        'B' => [['name' => 'Canada', 'code' => 'CAN'], ['name' => 'Switzerland', 'code' => 'SUI'], ['name' => 'Qatar', 'code' => 'QAT'], ['name' => 'Bosnia and Herzegovina', 'code' => 'BIH']],
        'C' => [['name' => 'Brazil', 'code' => 'BRA'], ['name' => 'Morocco', 'code' => 'MAR'], ['name' => 'Scotland', 'code' => 'SCO'], ['name' => 'Haiti', 'code' => 'HAI']],
        'D' => [['name' => 'United States', 'code' => 'USA'], ['name' => 'Paraguay', 'code' => 'PAR'], ['name' => 'Australia', 'code' => 'AUS'], ['name' => 'Turkey', 'code' => 'TUR']],
        'E' => [['name' => 'Germany', 'code' => 'GER'], ['name' => 'Ecuador', 'code' => 'ECU'], ['name' => 'Ivory Coast', 'code' => 'CIV'], ['name' => 'Curacao', 'code' => 'CUW']],
        'F' => [['name' => 'Netherlands', 'code' => 'NED'], ['name' => 'Japan', 'code' => 'JPN'], ['name' => 'Tunisia', 'code' => 'TUN'], ['name' => 'Sweden', 'code' => 'SWE']],
        'G' => [['name' => 'Belgium', 'code' => 'BEL'], ['name' => 'Iran', 'code' => 'IRN'], ['name' => 'Egypt', 'code' => 'EGY'], ['name' => 'New Zealand', 'code' => 'NZL']],
        'H' => [['name' => 'Spain', 'code' => 'ESP'], ['name' => 'Uruguay', 'code' => 'URU'], ['name' => 'Saudi Arabia', 'code' => 'KSA'], ['name' => 'Cape Verde', 'code' => 'CPV']],
        'I' => [['name' => 'France', 'code' => 'FRA'], ['name' => 'Senegal', 'code' => 'SEN'], ['name' => 'Norway', 'code' => 'NOR'], ['name' => 'Iraq', 'code' => 'IRQ']],
        'J' => [['name' => 'Argentina', 'code' => 'ARG'], ['name' => 'Austria', 'code' => 'AUT'], ['name' => 'Algeria', 'code' => 'ALG'], ['name' => 'Jordan', 'code' => 'JOR']],
        'K' => [['name' => 'Portugal', 'code' => 'POR'], ['name' => 'Colombia', 'code' => 'COL'], ['name' => 'Uzbekistan', 'code' => 'UZB'], ['name' => 'DR Congo', 'code' => 'COD']],
        'L' => [['name' => 'England', 'code' => 'ENG'], ['name' => 'Croatia', 'code' => 'CRO'], ['name' => 'Panama', 'code' => 'PAN'], ['name' => 'Ghana', 'code' => 'GHA']],
    ];

    /**
     * Host venue → IANA timezone, so a UTC kick-off can be rendered in real local time.
     *
     * @var array<string, string>
     */
    private const VENUE_TIMEZONES = [
        'Mexico City Stadium' => 'America/Mexico_City',
        'Guadalajara Stadium' => 'America/Mexico_City',
        'Monterrey Stadium' => 'America/Monterrey',
        'Atlanta Stadium' => 'America/New_York',
        'Toronto Stadium' => 'America/Toronto',
        'San Francisco Bay Stadium' => 'America/Los_Angeles',
        'Los Angeles Stadium' => 'America/Los_Angeles',
        'BC Place Vancouver' => 'America/Vancouver',
        'Seattle Stadium' => 'America/Los_Angeles',
        'New York New Jersey Stadium' => 'America/New_York',
        'Boston Stadium' => 'America/New_York',
        'Philadelphia Stadium' => 'America/New_York',
        'Miami Stadium' => 'America/New_York',
        'Houston Stadium' => 'America/Chicago',
        'Kansas City Stadium' => 'America/Chicago',
        'Dallas Stadium' => 'America/Chicago',
    ];

    /**
     * Official group-stage fixtures (FIFA World Cup 2026), per group:
     * [FIFA match number, home position, away position, date, kick-off time in ET, venue].
     *
     * Match numbers follow FIFA's chronological order across the WHOLE group stage (match 1 = the
     * opener, then by kickoff), so they are intentionally NOT sequential within a group. The entries
     * stay grouped by letter (in each group's own chronological order) for readability. Positions are
     * 1–4 in the seeded group order. Times are ET (EDT, UTC−4) and stored as UTC by {@see kickoff()}.
     *
     * @var array<string, list<array{int, int, int, string, string, string}>>
     */
    private const GROUP_SCHEDULE = [
        'A' => [
            [1, 1, 3, '2026-06-11', '15:00', 'Mexico City Stadium'],
            [2, 2, 4, '2026-06-11', '22:00', 'Guadalajara Stadium'],
            [25, 4, 3, '2026-06-18', '12:00', 'Atlanta Stadium'],
            [28, 1, 2, '2026-06-18', '21:00', 'Guadalajara Stadium'],
            [53, 4, 1, '2026-06-24', '21:00', 'Mexico City Stadium'],
            [54, 3, 2, '2026-06-24', '21:00', 'Monterrey Stadium'],
        ],
        'B' => [
            [3, 1, 4, '2026-06-12', '15:00', 'Toronto Stadium'],
            [5, 3, 2, '2026-06-13', '15:00', 'San Francisco Bay Stadium'],
            [26, 2, 4, '2026-06-18', '15:00', 'Los Angeles Stadium'],
            [27, 1, 3, '2026-06-18', '18:00', 'BC Place Vancouver'],
            [49, 2, 1, '2026-06-24', '15:00', 'BC Place Vancouver'],
            [50, 4, 3, '2026-06-24', '15:00', 'Seattle Stadium'],
        ],
        'C' => [
            [6, 1, 2, '2026-06-13', '18:00', 'New York New Jersey Stadium'],
            [7, 4, 3, '2026-06-13', '21:00', 'Boston Stadium'],
            [30, 3, 2, '2026-06-19', '18:00', 'Boston Stadium'],
            [31, 1, 4, '2026-06-19', '20:30', 'Philadelphia Stadium'],
            [51, 3, 1, '2026-06-24', '18:00', 'Miami Stadium'],
            [52, 2, 4, '2026-06-24', '18:00', 'Atlanta Stadium'],
        ],
        'D' => [
            [4, 1, 2, '2026-06-12', '21:00', 'Los Angeles Stadium'],
            [8, 3, 4, '2026-06-14', '00:00', 'BC Place Vancouver'],
            [29, 1, 3, '2026-06-19', '15:00', 'Seattle Stadium'],
            [32, 4, 2, '2026-06-19', '23:00', 'San Francisco Bay Stadium'],
            [59, 4, 1, '2026-06-25', '22:00', 'Los Angeles Stadium'],
            [60, 2, 3, '2026-06-25', '22:00', 'San Francisco Bay Stadium'],
        ],
        'E' => [
            [9, 1, 4, '2026-06-14', '13:00', 'Houston Stadium'],
            [11, 3, 2, '2026-06-14', '19:00', 'Philadelphia Stadium'],
            [34, 1, 3, '2026-06-20', '16:00', 'Toronto Stadium'],
            [35, 2, 4, '2026-06-20', '20:00', 'Kansas City Stadium'],
            [56, 2, 1, '2026-06-25', '16:00', 'New York New Jersey Stadium'],
            [55, 4, 3, '2026-06-25', '16:00', 'Philadelphia Stadium'],
        ],
        'F' => [
            [10, 1, 2, '2026-06-14', '16:00', 'Dallas Stadium'],
            [12, 4, 3, '2026-06-14', '22:00', 'Monterrey Stadium'],
            [33, 1, 4, '2026-06-20', '13:00', 'Houston Stadium'],
            [36, 3, 2, '2026-06-21', '00:00', 'Monterrey Stadium'],
            [58, 3, 1, '2026-06-25', '19:00', 'Kansas City Stadium'],
            [57, 2, 4, '2026-06-25', '19:00', 'Dallas Stadium'],
        ],
        'G' => [
            [14, 1, 3, '2026-06-15', '15:00', 'Seattle Stadium'],
            [16, 2, 4, '2026-06-15', '21:00', 'Los Angeles Stadium'],
            [38, 1, 2, '2026-06-21', '15:00', 'Los Angeles Stadium'],
            [40, 4, 3, '2026-06-21', '21:00', 'BC Place Vancouver'],
            [66, 4, 1, '2026-06-26', '23:00', 'BC Place Vancouver'],
            [65, 3, 2, '2026-06-26', '23:00', 'Seattle Stadium'],
        ],
        'H' => [
            [13, 1, 4, '2026-06-15', '12:00', 'Atlanta Stadium'],
            [15, 3, 2, '2026-06-15', '18:00', 'Miami Stadium'],
            [37, 1, 3, '2026-06-21', '12:00', 'Atlanta Stadium'],
            [39, 2, 4, '2026-06-21', '18:00', 'Miami Stadium'],
            [64, 2, 1, '2026-06-26', '20:00', 'Guadalajara Stadium'],
            [63, 4, 3, '2026-06-26', '20:00', 'Houston Stadium'],
        ],
        'I' => [
            [17, 1, 2, '2026-06-16', '15:00', 'New York New Jersey Stadium'],
            [18, 4, 3, '2026-06-16', '18:00', 'Boston Stadium'],
            [42, 1, 4, '2026-06-22', '17:00', 'Philadelphia Stadium'],
            [43, 3, 2, '2026-06-22', '20:00', 'New York New Jersey Stadium'],
            [61, 3, 1, '2026-06-26', '15:00', 'Boston Stadium'],
            [62, 2, 4, '2026-06-26', '15:00', 'Toronto Stadium'],
        ],
        'J' => [
            [19, 1, 3, '2026-06-16', '21:00', 'Kansas City Stadium'],
            [20, 2, 4, '2026-06-17', '00:00', 'San Francisco Bay Stadium'],
            [41, 1, 2, '2026-06-22', '13:00', 'Dallas Stadium'],
            [44, 4, 3, '2026-06-22', '23:00', 'San Francisco Bay Stadium'],
            [72, 4, 1, '2026-06-27', '22:00', 'Dallas Stadium'],
            [71, 3, 2, '2026-06-27', '22:00', 'Kansas City Stadium'],
        ],
        'K' => [
            [21, 1, 4, '2026-06-17', '13:00', 'Houston Stadium'],
            [24, 3, 2, '2026-06-17', '22:00', 'Mexico City Stadium'],
            [45, 1, 3, '2026-06-23', '13:00', 'Houston Stadium'],
            [48, 2, 4, '2026-06-23', '22:00', 'Guadalajara Stadium'],
            [69, 2, 1, '2026-06-27', '19:30', 'Miami Stadium'],
            [70, 4, 3, '2026-06-27', '19:30', 'Atlanta Stadium'],
        ],
        'L' => [
            [22, 1, 2, '2026-06-17', '16:00', 'Dallas Stadium'],
            [23, 4, 3, '2026-06-17', '19:00', 'Toronto Stadium'],
            [46, 1, 4, '2026-06-23', '16:00', 'Boston Stadium'],
            [47, 3, 2, '2026-06-23', '19:00', 'Toronto Stadium'],
            [67, 3, 1, '2026-06-27', '17:00', 'New York New Jersey Stadium'],
            [68, 2, 4, '2026-06-27', '17:00', 'Philadelphia Stadium'],
        ],
    ];

    /**
     * Official knockout schedule keyed by match number (the app's knockout numbers follow
     * FIFA's): [date, kick-off time in ET, venue]. All 32 entries use the official FIFA times;
     * match 103 (third place) kicks off 17:00 ET (21:00 UTC).
     *
     * @var array<int, array{string, string, string}>
     */
    private const KNOCKOUT_SCHEDULE = [
        73 => ['2026-06-28', '15:00', 'Los Angeles Stadium'],
        74 => ['2026-06-29', '16:30', 'Boston Stadium'],
        75 => ['2026-06-29', '21:00', 'Monterrey Stadium'],
        76 => ['2026-06-29', '13:00', 'Houston Stadium'],
        77 => ['2026-06-30', '17:00', 'New York New Jersey Stadium'],
        78 => ['2026-06-30', '13:00', 'Dallas Stadium'],
        79 => ['2026-06-30', '21:00', 'Mexico City Stadium'],
        80 => ['2026-07-01', '12:00', 'Atlanta Stadium'],
        81 => ['2026-07-01', '20:00', 'San Francisco Bay Stadium'],
        82 => ['2026-07-01', '16:00', 'Seattle Stadium'],
        83 => ['2026-07-02', '19:00', 'Toronto Stadium'],
        84 => ['2026-07-02', '15:00', 'Los Angeles Stadium'],
        85 => ['2026-07-02', '23:00', 'BC Place Vancouver'],
        86 => ['2026-07-03', '18:00', 'Miami Stadium'],
        87 => ['2026-07-03', '21:30', 'Kansas City Stadium'],
        88 => ['2026-07-03', '14:00', 'Dallas Stadium'],
        89 => ['2026-07-04', '17:00', 'Philadelphia Stadium'],
        90 => ['2026-07-04', '13:00', 'Houston Stadium'],
        91 => ['2026-07-05', '16:00', 'New York New Jersey Stadium'],
        92 => ['2026-07-05', '20:00', 'Mexico City Stadium'],
        93 => ['2026-07-06', '15:00', 'Dallas Stadium'],
        94 => ['2026-07-06', '20:00', 'Seattle Stadium'],
        95 => ['2026-07-07', '12:00', 'Atlanta Stadium'],
        96 => ['2026-07-07', '16:00', 'BC Place Vancouver'],
        97 => ['2026-07-09', '16:00', 'Boston Stadium'],
        98 => ['2026-07-10', '15:00', 'Los Angeles Stadium'],
        99 => ['2026-07-11', '17:00', 'Miami Stadium'],
        100 => ['2026-07-11', '21:00', 'Kansas City Stadium'],
        101 => ['2026-07-14', '15:00', 'Dallas Stadium'],
        102 => ['2026-07-15', '15:00', 'Atlanta Stadium'],
        103 => ['2026-07-18', '17:00', 'Miami Stadium'],
        104 => ['2026-07-19', '15:00', 'New York New Jersey Stadium'],
    ];

    /**
     * The 7 phases in progression order.
     *
     * @var list<array{key: PhaseKey, type: PhaseType, name: string}>
     */
    private const PHASES = [
        ['key' => PhaseKey::Group, 'type' => PhaseType::Group, 'name' => 'Group Stage'],
        ['key' => PhaseKey::RoundOf32, 'type' => PhaseType::Knockout, 'name' => 'Round of 32'],
        ['key' => PhaseKey::RoundOf16, 'type' => PhaseType::Knockout, 'name' => 'Round of 16'],
        ['key' => PhaseKey::QuarterFinals, 'type' => PhaseType::Knockout, 'name' => 'Quarter-finals'],
        ['key' => PhaseKey::SemiFinals, 'type' => PhaseType::Knockout, 'name' => 'Semi-finals'],
        ['key' => PhaseKey::ThirdPlace, 'type' => PhaseType::Knockout, 'name' => 'Third-place Play-off'],
        ['key' => PhaseKey::Final, 'type' => PhaseType::Knockout, 'name' => 'Final'],
    ];

    /**
     * Seed the World Cup 2026 tournament structure idempotently.
     */
    public function run(): void
    {
        DB::transaction(function () {
            $tournament = $this->seedTournament();
            $this->seedPools($tournament);
            $phases = $this->seedPhases($tournament);
            $groupTeams = $this->seedGroupsAndTeams($tournament);
            $this->seedGroupFixtures($tournament, $phases[PhaseKey::Group->value], $groupTeams);
            $this->seedKnockoutFixtures($tournament, $phases);
        });
    }

    private function seedTournament(): Tournament
    {
        return Tournament::updateOrCreate(
            ['slug' => 'world-cup-2026'],
            [
                'name' => 'World Cup 2026',
                'sport' => Sport::Soccer,
                'status' => TournamentStatus::Upcoming,
                'starts_on' => '2026-06-11',
                'ends_on' => '2026-07-19',
            ],
        );
    }

    /**
     * The playable pools over the competition. Both share the tournament's structure and official
     * results but layer on their own scoring strategy and prediction lock:
     *
     *  - "Bolão Copa - FF&A" (source Wagner Figueiredo, upfront bracket): predict the whole tournament before kickoff;
     *  - "Bolão dos Brothers - Copa" (source Bruno Gasparin, phased bracket): predict the group stage
     *    upfront, then each knockout round against the official match-ups, with rising round multipliers.
     *
     * The scoring_config for each is duplicated verbatim in {@see PoolFactory} (the default state
     * and the {@see PoolFactory::phasedBracket()} state) — keep them in sync.
     */
    private function seedPools(Tournament $tournament): void
    {
        Pool::updateOrCreate(
            ['slug' => 'world-cup-2026-ffa'],
            [
                'tournament_id' => $tournament->id,
                'name' => 'Bolão Copa - FF&A',
                'source' => 'Wagner Figueiredo',
                'accent' => PoolAccent::Pitch,
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
                // No override: the lock derives from the first group kickoff (minus the buffer).
                'predictions_lock_at' => null,
                'entry_price' => 50.00,
                'currency' => 'BRL',
                'house_fee_percentage' => 10.00,
                'prize_structure' => [
                    ['place' => 1, 'percentage' => 70],
                    ['place' => 2, 'percentage' => 20],
                    ['place' => 3, 'percentage' => 10],
                ],
            ],
        );

        Pool::updateOrCreate(
            ['slug' => 'world-cup-2026-brothers'],
            [
                'tournament_id' => $tournament->id,
                'name' => 'Bolão dos Brothers - Copa',
                'source' => 'Bruno Gasparin',
                'accent' => PoolAccent::Teal,
                'scoring_strategy' => ScoringStrategy::PhasedBracket,
                'scoring_config' => [
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
                ],
                // No override: the group lock derives from the first group kickoff (minus the
                // buffer); each knockout round locks the buffer before its own first kickoff.
                'predictions_lock_at' => null,
                'entry_price' => 30.00,
                'currency' => 'BRL',
                // No house cut — the whole buy-in goes into the pot ("100% to players").
                'house_fee_percentage' => 0.00,
                // A flatter split than Bolão Copa - FF&A so 2nd and 3rd are worth chasing, not a winner-take-most.
                'prize_structure' => [
                    ['place' => 1, 'percentage' => 50],
                    ['place' => 2, 'percentage' => 30],
                    ['place' => 3, 'percentage' => 20],
                ],
            ],
        );

        // A rolling ("Rolling Predictions") pool over the same tournament: every match is predicted
        // on its own clock, staying open until its kickoff. Its attributes live in
        // {@see rollingPoolAttributes()} so the targeted production seeder (AddRollingPoolSeeder) can
        // add this exact pool without re-running the whole seeder.
        Pool::updateOrCreate(
            ['slug' => 'world-cup-2026-brothers-2'],
            self::rollingPoolAttributes($tournament->id),
        );
    }

    /**
     * The attribute payload for the Rolling Predictions pool (everything but its slug). Scores
     * exactly like the phased pool (scoreline tiers, rising round multipliers, advancing bonus) —
     * only the per-match lock differs — so its scoring_config matches the phased pool's verbatim;
     * kept in sync with {@see PoolFactory::rollingBracket()}. Shared with {@see AddRollingPoolSeeder}.
     *
     * @return array<string, mixed>
     */
    public static function rollingPoolAttributes(int $tournamentId): array
    {
        return [
            'tournament_id' => $tournamentId,
            'name' => 'Bolão dos Brothers 2 - Copa',
            'source' => 'Bruno Gasparin',
            'accent' => PoolAccent::Violet,
            'scoring_strategy' => ScoringStrategy::RollingBracket,
            'scoring_config' => [
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
            ],
            // No override: each match locks at its own kickoff, so there is no single pool lock.
            'predictions_lock_at' => null,
            'entry_price' => 30.00,
            'currency' => 'BRL',
            'house_fee_percentage' => 0.00,
            'prize_structure' => [
                ['place' => 1, 'percentage' => 50],
                ['place' => 2, 'percentage' => 30],
                ['place' => 3, 'percentage' => 20],
            ],
        ];
    }

    /**
     * @return array<string, Phase> keyed by phase key value
     */
    private function seedPhases(Tournament $tournament): array
    {
        $phases = [];

        foreach (self::PHASES as $index => $phase) {
            $phases[$phase['key']->value] = Phase::updateOrCreate(
                ['tournament_id' => $tournament->id, 'key' => $phase['key']->value],
                ['type' => $phase['type']->value, 'name' => $phase['name'], 'sort_order' => $index + 1],
            );
        }

        return $phases;
    }

    /**
     * @return array<string, array<int, int>> team ids keyed by [group name][position]
     */
    private function seedGroupsAndTeams(Tournament $tournament): array
    {
        $groupTeams = [];
        $sortOrder = 1;

        foreach (self::GROUPS as $name => $teams) {
            $group = Group::updateOrCreate(
                ['tournament_id' => $tournament->id, 'name' => $name],
                ['sort_order' => $sortOrder++],
            );

            $sync = [];

            foreach ($teams as $position => $definition) {
                $team = Team::updateOrCreate(
                    ['name' => $definition['name']],
                    ['code' => $definition['code'], 'is_placeholder' => $definition['placeholder'] ?? false],
                );

                $sync[$team->id] = ['position' => $position + 1];
                $groupTeams[$name][$position + 1] = $team->id;
            }

            $group->teams()->sync($sync);
        }

        return $groupTeams;
    }

    /**
     * Build the 72 group fixtures (6 per group, match numbers 1–72 in FIFA chronological order)
     * from the official 2026 schedule: real pairings, dates, venues and kick-off times (stored as
     * UTC). The FIFA match number is carried explicitly on each {@see GROUP_SCHEDULE} entry.
     *
     * @param  array<string, array<int, int>>  $groupTeams
     */
    private function seedGroupFixtures(Tournament $tournament, Phase $phase, array $groupTeams): void
    {
        foreach (self::GROUP_SCHEDULE as $name => $matches) {
            $group = $tournament->groups()->where('name', $name)->firstOrFail();

            foreach ($matches as [$matchNumber, $home, $away, $date, $time, $venue]) {
                Fixture::updateOrCreate(
                    ['tournament_id' => $tournament->id, 'match_number' => $matchNumber],
                    [
                        'phase_id' => $phase->id,
                        'group_id' => $group->id,
                        'home_team_id' => $groupTeams[$name][$home],
                        'away_team_id' => $groupTeams[$name][$away],
                        'kicks_off_at' => $this->kickoff($date, $time),
                        'venue' => $venue,
                        'venue_timezone' => self::VENUE_TIMEZONES[$venue],
                    ],
                );
            }
        }
    }

    /**
     * Parse an Eastern-Time kick-off (the schedule's source unit, EDT) into a UTC instant.
     */
    private function kickoff(string $date, string $time): Carbon
    {
        return Carbon::parse("$date $time", 'America/New_York')->setTimezone('UTC');
    }

    /**
     * Build the 32 knockout fixtures (match numbers 73–104) and wire their feeders.
     *
     * @param  array<string, Phase>  $phases
     */
    private function seedKnockoutFixtures(Tournament $tournament, array $phases): void
    {
        // Pass 1: create every bracket slot with its display labels (no feeders yet).
        $slots = $this->bracketDefinition();
        $fixtures = [];

        foreach ($slots as $slot) {
            [$date, $time, $venue] = self::KNOCKOUT_SCHEDULE[$slot['match_number']];

            $fixtures[$slot['slot']] = Fixture::updateOrCreate(
                ['tournament_id' => $tournament->id, 'match_number' => $slot['match_number']],
                [
                    'phase_id' => $phases[$slot['phase']->value]->id,
                    'bracket_slot' => $slot['slot'],
                    'home_placeholder_label' => $slot['home_label'],
                    'away_placeholder_label' => $slot['away_label'],
                    'kicks_off_at' => $this->kickoff($date, $time),
                    'venue' => $venue,
                    'venue_timezone' => self::VENUE_TIMEZONES[$venue],
                ],
            );
        }

        // Pass 2: link knockout fixtures fed by prior knockout fixtures.
        foreach ($slots as $slot) {
            if ($slot['home_feeder'] === null && $slot['away_feeder'] === null) {
                continue;
            }

            $fixtures[$slot['slot']]->update([
                'home_feeder_fixture_id' => $fixtures[$slot['home_feeder'][0]]->id,
                'home_feeder_outcome' => $slot['home_feeder'][1]->value,
                'away_feeder_fixture_id' => $fixtures[$slot['away_feeder'][0]]->id,
                'away_feeder_outcome' => $slot['away_feeder'][1]->value,
            ]);
        }
    }

    /**
     * The official FIFA World Cup 2026 knockout bracket. Match numbers 73–88 are the Round of
     * 32 (fed from the group standings), then a single-elimination tree (R16 → QF → SF → Final,
     * matches 89–104) plus the third-place play-off (103) off the semifinal losers. Winner-vs-
     * third Round-of-32 slots carry the eligible-group set as their label; the resolver fills
     * them via {@see ThirdPlaceAllocation}.
     *
     * @return list<array{
     *     slot: string,
     *     phase: PhaseKey,
     *     match_number: int,
     *     home_label: string,
     *     away_label: string,
     *     home_feeder: ?array{string, FeederOutcome},
     *     away_feeder: ?array{string, FeederOutcome}
     * }>
     */
    private function bracketDefinition(): array
    {
        $slots = [];

        $thirdLabel = fn (int $matchNumber): string => '3rd Group '.implode('/', ThirdPlaceAllocation::ELIGIBLE_GROUPS[$matchNumber]);

        // Round of 32 (matches 73–88) — fed from group standings (no fixture feeders).
        $r32 = [
            73 => ['Runner-up Group A', 'Runner-up Group B'],
            74 => ['Winner Group E', $thirdLabel(74)],
            75 => ['Winner Group F', 'Runner-up Group C'],
            76 => ['Winner Group C', 'Runner-up Group F'],
            77 => ['Winner Group I', $thirdLabel(77)],
            78 => ['Runner-up Group E', 'Runner-up Group I'],
            79 => ['Winner Group A', $thirdLabel(79)],
            80 => ['Winner Group L', $thirdLabel(80)],
            81 => ['Winner Group D', $thirdLabel(81)],
            82 => ['Winner Group G', $thirdLabel(82)],
            83 => ['Runner-up Group K', 'Runner-up Group L'],
            84 => ['Winner Group H', 'Runner-up Group J'],
            85 => ['Winner Group B', $thirdLabel(85)],
            86 => ['Winner Group J', 'Runner-up Group H'],
            87 => ['Winner Group K', $thirdLabel(87)],
            88 => ['Runner-up Group D', 'Runner-up Group G'],
        ];

        foreach ($r32 as $matchNumber => [$home, $away]) {
            $slots[] = [
                'slot' => $this->slotForMatch($matchNumber),
                'phase' => PhaseKey::RoundOf32,
                'match_number' => $matchNumber,
                'home_label' => $home,
                'away_label' => $away,
                'home_feeder' => null,
                'away_feeder' => null,
            ];
        }

        // Single-elimination tree (matches 89–104): match number => [phase, home feeder match, away feeder match].
        $feeders = [
            89 => [PhaseKey::RoundOf16, 74, 77],
            90 => [PhaseKey::RoundOf16, 73, 75],
            91 => [PhaseKey::RoundOf16, 76, 78],
            92 => [PhaseKey::RoundOf16, 79, 80],
            93 => [PhaseKey::RoundOf16, 83, 84],
            94 => [PhaseKey::RoundOf16, 81, 82],
            95 => [PhaseKey::RoundOf16, 86, 88],
            96 => [PhaseKey::RoundOf16, 85, 87],
            97 => [PhaseKey::QuarterFinals, 89, 90],
            98 => [PhaseKey::QuarterFinals, 93, 94],
            99 => [PhaseKey::QuarterFinals, 91, 92],
            100 => [PhaseKey::QuarterFinals, 95, 96],
            101 => [PhaseKey::SemiFinals, 97, 98],
            102 => [PhaseKey::SemiFinals, 99, 100],
            104 => [PhaseKey::Final, 101, 102],
        ];

        foreach ($feeders as $matchNumber => [$phase, $homeMatch, $awayMatch]) {
            $slots[] = $this->feederSlot(
                $this->slotForMatch($matchNumber),
                $phase,
                $matchNumber,
                $this->slotForMatch($homeMatch),
                $this->slotForMatch($awayMatch),
            );
        }

        // Third-place play-off (match 103) — the two semifinal losers.
        $slots[] = [
            'slot' => 'TP',
            'phase' => PhaseKey::ThirdPlace,
            'match_number' => 103,
            'home_label' => 'Loser SF-1',
            'away_label' => 'Loser SF-2',
            'home_feeder' => ['SF-1', FeederOutcome::Loser],
            'away_feeder' => ['SF-2', FeederOutcome::Loser],
        ];

        return $slots;
    }

    /**
     * The internal bracket-slot handle for a knockout match number.
     */
    private function slotForMatch(int $matchNumber): string
    {
        return match (true) {
            $matchNumber >= 73 && $matchNumber <= 88 => 'R32-'.($matchNumber - 72),
            $matchNumber >= 89 && $matchNumber <= 96 => 'R16-'.($matchNumber - 88),
            $matchNumber >= 97 && $matchNumber <= 100 => 'QF-'.($matchNumber - 96),
            $matchNumber >= 101 && $matchNumber <= 102 => 'SF-'.($matchNumber - 100),
            $matchNumber === 103 => 'TP',
            default => 'F',
        };
    }

    /**
     * Build a knockout slot fed by the winners of two prior fixtures.
     *
     * @return array{
     *     slot: string,
     *     phase: PhaseKey,
     *     match_number: int,
     *     home_label: string,
     *     away_label: string,
     *     home_feeder: array{string, FeederOutcome},
     *     away_feeder: array{string, FeederOutcome}
     * }
     */
    private function feederSlot(string $slot, PhaseKey $phase, int $matchNumber, string $homeSlot, string $awaySlot): array
    {
        return [
            'slot' => $slot,
            'phase' => $phase,
            'match_number' => $matchNumber,
            'home_label' => 'Winner '.$homeSlot,
            'away_label' => 'Winner '.$awaySlot,
            'home_feeder' => [$homeSlot, FeederOutcome::Winner],
            'away_feeder' => [$awaySlot, FeederOutcome::Winner],
        ];
    }
}
