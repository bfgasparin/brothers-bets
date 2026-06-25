<?php

namespace Tests\Unit\Services\Scoring;

use App\Models\Entry;
use App\Models\Pool;
use App\Models\Tournament;
use App\Models\User;
use App\Services\Scoring\MatchdayCatalog;
use App\Services\Scoring\MatchdayLeaderboard;
use App\Services\Scoring\MatchdayLeaderboardView;
use App\Services\Scoring\ScoreEngine;
use Database\Seeders\WorldCup2026Seeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Concerns\InteractsWithOfficialResults;
use Tests\Concerns\InteractsWithPredictions;
use Tests\TestCase;

class MatchdayLeaderboardTest extends TestCase
{
    use InteractsWithOfficialResults;
    use InteractsWithPredictions;
    use RefreshDatabase;

    private Tournament $tournament;

    private Pool $pool;

    private Entry $me;

    private Entry $rival;

    private MatchdayLeaderboard $builder;

    private MatchdayCatalog $catalog;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed(WorldCup2026Seeder::class);
        $this->tournament = Tournament::firstOrFail();
        $this->pool = $this->tournament->pools()->where('slug', 'world-cup-2026-ffa')->firstOrFail();
        $this->catalog = new MatchdayCatalog;
        $this->builder = new MatchdayLeaderboard($this->catalog, new ScoreEngine);

        // Me predicts the seed order (the official outcome), the rival predicts the reverse.
        $this->me = Entry::factory()->for($this->pool)->for(User::factory()->create(['name' => 'Me']))->create();
        $this->rival = Entry::factory()->for($this->pool)->for(User::factory()->create(['name' => 'Rival']))->create();
        $this->predictAllGroups($this->me, $this->tournament, $this->seedOrderScores());
        $this->predictAllGroups($this->rival, $this->tournament, $this->reverseSeedScores());
    }

    public function test_current_matchday_reports_live_standings_and_per_matchday_cards(): void
    {
        $this->recordMatchdayResults($this->tournament, 'group-1', $this->seedOrderScores());
        (new ScoreEngine)->recompute($this->pool);

        $view = $this->builder->build($this->pool, $this->me->user_id, null);

        // With only matchday 1 settled, it is the current matchday and the default selection.
        $this->assertSame('group-1', $view->selectedKey);

        $overall = $this->board($view, 'overall');
        $this->assertSame('Me', $overall['rows'][0]['name'] === 'You' ? 'Me' : $overall['rows'][0]['name']);
        $this->assertTrue($overall['rows'][0]['is_me']);
        $this->assertGreaterThan($overall['rows'][1]['primary_value'], $overall['rows'][0]['primary_value']);

        // Cards for matchday 1, expressed in Overall points.
        $stats = $overall['matchday_stats'];
        $this->assertSame($this->me->id, $stats['you']['entry_id']);
        $this->assertSame($this->me->id, $stats['top']['leaders'][0]['entry_id']);
        $this->assertSame($this->rival->id, $stats['lowest']['leaders'][0]['entry_id']);
        $this->assertGreaterThan($stats['lowest']['leaders'][0]['value'], $stats['top']['leaders'][0]['value']);
        $this->assertSame($stats['top']['leaders'][0]['value'], $stats['you']['value']);
    }

    public function test_tied_matchday_leaders_are_all_reported_with_a_count(): void
    {
        // A twin who predicts the same as Me ties them for the matchday's top score; Rival is alone
        // at the bottom.
        $twin = Entry::factory()->for($this->pool)->for(User::factory()->create(['name' => 'Twin']))->create();
        $this->predictAllGroups($twin, $this->tournament, $this->seedOrderScores());

        $this->recordMatchdayResults($this->tournament, 'group-1', $this->seedOrderScores());
        (new ScoreEngine)->recompute($this->pool);

        $stats = $this->board(
            $this->builder->build($this->pool, $this->me->user_id, null),
            'overall',
        )['matchday_stats'];

        // Top is a two-way tie: both Me and Twin are reported, with the true count.
        $this->assertSame(2, $stats['top']['count']);
        $topIds = array_column($stats['top']['leaders'], 'entry_id');
        $this->assertContains($this->me->id, $topIds);
        $this->assertContains($twin->id, $topIds);

        // Lowest is a clear standout: a single leader and a count of one.
        $this->assertSame(1, $stats['lowest']['count']);
        $this->assertSame($this->rival->id, $stats['lowest']['leaders'][0]['entry_id']);
    }

    public function test_every_tied_leader_is_reported_not_just_the_first_three(): void
    {
        // Three twins join Me at the top, making a four-way tie — more than the avatar stack shows.
        $twins = collect(['Twin A', 'Twin B', 'Twin C'])->map(function (string $name): Entry {
            $twin = Entry::factory()->for($this->pool)->for(User::factory()->create(['name' => $name]))->create();
            $this->predictAllGroups($twin, $this->tournament, $this->seedOrderScores());

            return $twin;
        });

        $this->recordMatchdayResults($this->tournament, 'group-1', $this->seedOrderScores());
        (new ScoreEngine)->recompute($this->pool);

        $stats = $this->board(
            $this->builder->build($this->pool, $this->me->user_id, null),
            'overall',
        )['matchday_stats'];

        // The full tie is carried — count and the leaders list both hold all four, not a slice of three.
        $this->assertSame(4, $stats['top']['count']);
        $this->assertCount(4, $stats['top']['leaders']);
        $topIds = array_column($stats['top']['leaders'], 'entry_id');
        $this->assertContains($this->me->id, $topIds);
        foreach ($twins as $twin) {
            $this->assertContains($twin->id, $topIds);
        }
    }

    public function test_a_past_matchday_is_frozen_at_its_own_end(): void
    {
        // Settle matchday 1, then matchday 2; both played out in seed order.
        $this->recordMatchdayResults($this->tournament, 'group-1', $this->seedOrderScores());
        $this->recordMatchdayResults($this->tournament, 'group-2', $this->seedOrderScores());
        (new ScoreEngine)->recompute($this->pool);

        $afterMd1 = $this->builder->build($this->pool, $this->me->user_id, 'group-1');
        $afterMd2 = $this->builder->build($this->pool, $this->me->user_id, 'group-2');

        $myMd1 = $this->myRow($afterMd1, 'overall')['primary_value'];
        $myMd2 = $this->myRow($afterMd2, 'overall')['primary_value'];

        // Matchday 2 is the current view; matchday 1 is a frozen, smaller historical snapshot.
        $this->assertGreaterThan(0, $myMd1);
        $this->assertGreaterThan($myMd1, $myMd2);

        // The matchday-2 card shows only what was earned in matchday 2 (the cumulative gain).
        $md2Card = $this->board($afterMd2, 'overall')['matchday_stats']['you']['value'];
        $this->assertSame($myMd2 - $myMd1, $md2Card);
    }

    public function test_matchday_descriptors_report_status_and_the_current_marker(): void
    {
        $this->recordMatchdayResults($this->tournament, 'group-1', $this->seedOrderScores());
        (new ScoreEngine)->recompute($this->pool);

        $view = $this->builder->build($this->pool, $this->me->user_id, null);
        $byKey = collect($view->matchdays)->keyBy('key');

        $this->assertSame('complete', $byKey['group-1']['status']);
        $this->assertTrue($byKey['group-1']['is_current']);
        $this->assertSame('upcoming', $byKey['group-2']['status']);
        $this->assertFalse($byKey['group-2']['is_current']);
        $this->assertSame('upcoming', $byKey['final']['status']);
    }

    public function test_requesting_an_unplayed_future_matchday_falls_back_to_current(): void
    {
        $this->recordMatchdayResults($this->tournament, 'group-1', $this->seedOrderScores());
        (new ScoreEngine)->recompute($this->pool);

        $view = $this->builder->build($this->pool, $this->me->user_id, 'final');

        $this->assertSame('group-1', $view->selectedKey);
    }

    public function test_it_names_the_biggest_climber_and_faller_for_a_past_matchday(): void
    {
        [$climber, $faller] = $this->seedAMatchdayTwoSwap();

        // Settle a third matchday so group-2 is a *past* matchday (group-3 is current).
        $this->recordMatchdayResults($this->tournament, 'group-3', $this->seedOrderScores());
        (new ScoreEngine)->recompute($this->pool);

        $stats = $this->board(
            $this->builder->build($this->pool, $this->me->user_id, 'group-2'),
            'overall',
        )['matchday_stats'];

        $this->assertSame($climber->id, $stats['biggest_climber']['leaders'][0]['entry_id']);
        $this->assertSame(1, $stats['biggest_climber']['leaders'][0]['value']);
        $this->assertSame($faller->id, $stats['biggest_faller']['leaders'][0]['entry_id']);
        $this->assertSame(1, $stats['biggest_faller']['leaders'][0]['value']);
    }

    public function test_current_matchday_movement_is_measured_against_the_previous_matchday(): void
    {
        [$climber, $faller] = $this->seedAMatchdayTwoSwap();
        (new ScoreEngine)->recompute($this->pool);

        // group-2 is the current matchday; movement compares its standings to the end of group-1.
        $view = $this->builder->build($this->pool, $this->me->user_id, null);
        $this->assertSame('group-2', $view->selectedKey);

        $this->assertSame('up', $this->rowFor($view, 'overall', $climber->id)['movement']);
        $this->assertSame(1, $this->rowFor($view, 'overall', $climber->id)['movement_delta']);
        $this->assertSame('down', $this->rowFor($view, 'overall', $faller->id)['movement']);
        $this->assertSame(1, $this->rowFor($view, 'overall', $faller->id)['movement_delta']);
    }

    public function test_the_first_matchday_shows_everyone_as_new_with_no_movers(): void
    {
        $this->recordMatchdayResults($this->tournament, 'group-1', $this->seedOrderScores());
        (new ScoreEngine)->recompute($this->pool);

        // Matchday 1 has no prior standings to move against — everyone is "new", no climber/faller.
        $overall = $this->board(
            $this->builder->build($this->pool, $this->me->user_id, null),
            'overall',
        );

        foreach ($overall['rows'] as $row) {
            $this->assertSame('new', $row['movement']);
        }

        $this->assertNull($overall['matchday_stats']['biggest_climber']);
        $this->assertNull($overall['matchday_stats']['biggest_faller']);
    }

    /**
     * Settle matchdays 1 and 2 so a climber and a faller swap places between them: the faller leads
     * after MD1 (15/match) then scores nothing in MD2; the climber trails after MD1 (10/match) but
     * keeps scoring in MD2, overtaking the faller by the end of MD2.
     *
     * @return array{0: Entry, 1: Entry} [climber, faller]
     */
    private function seedAMatchdayTwoSwap(): array
    {
        $climber = Entry::factory()->for($this->pool)->for(User::factory()->create(['name' => 'Climber']))->create();
        $faller = Entry::factory()->for($this->pool)->for(User::factory()->create(['name' => 'Faller']))->create();

        // Tiers: correct outcome + one exact team goal = 15; correct outcome wrong goals = 10.
        $strongPartial = fn (int $home, int $away): array => $home < $away ? [3, 0] : [0, 3];
        $weakPartial = fn (int $home, int $away): array => $home < $away ? [2, 1] : [1, 2];

        $this->predictMatchday($faller, $this->tournament, 'group-1', $strongPartial);
        $this->predictMatchday($faller, $this->tournament, 'group-2', $this->reverseSeedScores());
        $this->predictMatchday($climber, $this->tournament, 'group-1', $weakPartial);
        $this->predictMatchday($climber, $this->tournament, 'group-2', $weakPartial);

        $this->recordMatchdayResults($this->tournament, 'group-1', $this->seedOrderScores());
        $this->recordMatchdayResults($this->tournament, 'group-2', $this->seedOrderScores());

        return [$climber, $faller];
    }

    /**
     * The inverse of {@see seedOrderScores()} — the worse-seeded team wins, so every pick is the
     * wrong outcome against an official seed-order result.
     *
     * @return callable(int, int): array{int, int}
     */
    private function reverseSeedScores(): callable
    {
        return fn (int $homePosition, int $awayPosition): array => $homePosition < $awayPosition
            ? [0, 1]
            : [1, 0];
    }

    /**
     * @return array<string, mixed>
     */
    private function board(MatchdayLeaderboardView $view, string $key): array
    {
        return collect($view->boards)->firstWhere('key', $key);
    }

    /**
     * @return array<string, mixed>
     */
    private function myRow(MatchdayLeaderboardView $view, string $boardKey): array
    {
        return collect($this->board($view, $boardKey)['rows'])->firstWhere('is_me', true);
    }

    /**
     * @return array<string, mixed>
     */
    private function rowFor(MatchdayLeaderboardView $view, string $boardKey, int $entryId): array
    {
        return collect($this->board($view, $boardKey)['rows'])->firstWhere('entry_id', $entryId);
    }
}
