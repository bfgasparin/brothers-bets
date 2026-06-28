<?php

namespace Tests\Feature;

use App\Enums\LeaderboardCategory;
use App\Models\Entry;
use App\Models\Fixture;
use App\Models\GroupPrediction;
use App\Models\KnockoutPrediction;
use App\Models\LeaderboardStanding;
use App\Models\Team;
use App\Models\Tournament;
use App\Models\User;
use App\Services\Scoring\ScoreEngine;
use Database\Seeders\WorldCup2026Seeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Storage;
use Inertia\Testing\AssertableInertia;
use Tests\Concerns\InteractsWithOfficialResults;
use Tests\Concerns\InteractsWithPredictions;
use Tests\TestCase;

class PoolControllerTest extends TestCase
{
    use InteractsWithOfficialResults;
    use InteractsWithPredictions;
    use RefreshDatabase;

    public function test_guests_are_redirected_from_the_pools_index(): void
    {
        $this->get(route('pools.index'))->assertRedirect(route('login'));
    }

    public function test_the_timezone_cookie_is_shared_to_the_frontend(): void
    {
        $this->seed(WorldCup2026Seeder::class);
        $this->actingAs(User::factory()->create());

        $this->withUnencryptedCookie('timezone', 'America/Sao_Paulo')
            ->get(route('pools.index'))
            ->assertInertia(fn (AssertableInertia $page) => $page
                ->where('timezone', 'America/Sao_Paulo')
            );
    }

    public function test_authenticated_users_see_the_seeded_pool_on_the_index(): void
    {
        $this->seed(WorldCup2026Seeder::class);
        $this->actingAs(User::factory()->create());

        $this->get(route('pools.index'))
            ->assertOk()
            ->assertInertia(fn (AssertableInertia $page) => $page
                ->component('pools/index')
                ->has('pools.data', 3)
                ->where('pools.data.0.slug', 'world-cup-2026-ffa')
                ->where('pools.data.1.slug', 'world-cup-2026-brothers')
                ->where('pools.data.2.slug', 'world-cup-2026-rolling')
                // All pools are played over the one tournament, so each carries its shared
                // identity plus a stable, distinct accent position used to tell them apart.
                ->where('pools.data.0.tournament.name', 'World Cup 2026')
                ->where('pools.data.0.accent_index', 0)
                ->where('pools.data.1.accent_index', 1)
                ->where('pools.data.2.accent_index', 2)
                // Each pool also carries its own stored accent colour, distinct per sibling.
                ->where('pools.data.0.source', 'Wagner Figueiredo')
                ->where('pools.data.0.accent', 'pitch')
                ->where('pools.data.0.name', 'Bolão Copa - FF&A')
                ->where('pools.data.1.name', 'Bolão dos Brothers - Copa')
                ->where('pools.data.1.source', 'Bruno Gasparin')
                ->where('pools.data.1.accent', 'teal')
                ->where('pools.data.2.name', 'Bolão dos Brothers 2 - Copa')
                ->where('pools.data.2.accent', 'violet')
                ->where('pools.current_page', 1)
                ->where('pools.last_page', 1)
                ->where('pools.total', 3)
            );
    }

    public function test_the_index_reports_each_pools_player_count(): void
    {
        $this->seed(WorldCup2026Seeder::class);
        $tournament = Tournament::firstOrFail();
        $ffa = $tournament->pools()->where('slug', 'world-cup-2026-ffa')->firstOrFail();

        // Two players have entered the FF&A pool; nobody has entered the Brothers pool yet. The
        // count is per pool, so the two sibling pools report different pool sizes.
        Entry::factory()->count(2)->for($ffa)->create();

        $this->actingAs(User::factory()->create());

        $this->get(route('pools.index'))
            ->assertOk()
            ->assertInertia(fn (AssertableInertia $page) => $page
                ->where('pools.data.0.slug', 'world-cup-2026-ffa')
                ->where('pools.data.0.players_count', 2)
                ->where('pools.data.1.slug', 'world-cup-2026-brothers')
                ->where('pools.data.1.players_count', 0)
            );
    }

    public function test_show_renders_groups_and_bracket(): void
    {
        $this->seed(WorldCup2026Seeder::class);
        $this->actingAs(User::factory()->create());

        $this->get(route('pools.show', 'world-cup-2026-ffa'))
            ->assertOk()
            ->assertInertia(fn (AssertableInertia $page) => $page
                ->component('pools/show')
                ->where('pool.slug', 'world-cup-2026-ffa')
                ->has('groups', 12)
                ->has('groups.0.teams', 4)
                ->has('groups.0.fixtures', 6)
                ->has('bracket', 6)
                ->where('bracket.0.phase_key', 'round_of_32')
                ->has('bracket.0.fixtures', 16)
                ->where('bracket.0.fixtures.0.home', null)
                ->whereNot('bracket.0.fixtures.0.home_label', null)
            );
    }

    public function test_show_stamps_each_fixture_with_its_matchday_and_lists_the_timeline(): void
    {
        $this->seed(WorldCup2026Seeder::class);
        $this->actingAs(User::factory()->create());

        $this->get(route('pools.show', 'world-cup-2026-ffa'))
            ->assertInertia(fn (AssertableInertia $page) => $page
                // Each match carries the matchday it belongs to, so the page can mark and group it.
                ->where('groups.0.fixtures.0.matchday_key', 'group-1')
                ->where('bracket.0.fixtures.0.matchday_key', 'round_of_32')
                // The ordered matchday timeline (shared with the leaderboard) drives the view switcher.
                ->has('matchdays', 9)
                ->where('matchdays.0.key', 'group-1')
                ->where('matchdays.0.short_label', 'MD1')
                ->where('matchdays.3.key', 'round_of_32')
                ->where('matchdays.8.key', 'final')
                // A pool-page descriptor is display-only — never the leaderboard's travel status.
                ->missing('matchdays.0.status')
            );
    }

    public function test_show_exposes_the_inputs_the_time_filter_reads(): void
    {
        // The pool page's Today/Upcoming filter is client-side, keyed off each fixture's kickoff and
        // whether it's settled (both goals in). Guard that those fields ship for groups and bracket.
        $this->seed(WorldCup2026Seeder::class);
        $this->actingAs(User::factory()->create());

        $this->get(route('pools.show', 'world-cup-2026-ffa'))
            ->assertInertia(fn (AssertableInertia $page) => $page
                ->whereNot('groups.0.fixtures.0.kicks_off_at', null)
                ->where('groups.0.fixtures.0.home_goals', null)
                ->where('groups.0.fixtures.0.away_goals', null)
                ->whereNot('bracket.0.fixtures.0.kicks_off_at', null)
                ->where('bracket.0.fixtures.0.home_goals', null)
                ->where('bracket.0.fixtures.0.away_goals', null)
            );
    }

    public function test_show_resolves_group_fixture_teams(): void
    {
        $this->seed(WorldCup2026Seeder::class);
        $this->actingAs(User::factory()->create());

        $this->get(route('pools.show', 'world-cup-2026-ffa'))
            ->assertInertia(fn (AssertableInertia $page) => $page
                ->whereNot('groups.0.fixtures.0.home', null)
                ->whereNot('groups.0.fixtures.0.away', null)
            );
    }

    public function test_show_exposes_knockout_kick_off_dates(): void
    {
        $this->seed(WorldCup2026Seeder::class);
        $this->actingAs(User::factory()->create());

        $this->get(route('pools.show', 'world-cup-2026-ffa'))
            ->assertInertia(fn (AssertableInertia $page) => $page
                ->whereNot('bracket.0.fixtures.0.kicks_off_at', null)
                ->whereNot('bracket.0.fixtures.0.venue', null)
                ->whereNot('bracket.0.fixtures.0.venue_timezone', null)
                ->whereNot('groups.0.fixtures.0.kicks_off_at', null)
                ->whereNot('groups.0.fixtures.0.venue', null)
                ->whereNot('groups.0.fixtures.0.venue_timezone', null)
            );
    }

    public function test_show_includes_no_prediction_without_an_entry(): void
    {
        $this->seed(WorldCup2026Seeder::class);
        $this->actingAs(User::factory()->create());

        $this->get(route('pools.show', 'world-cup-2026-ffa'))
            ->assertInertia(fn (AssertableInertia $page) => $page
                ->where('groups.0.fixtures.0.prediction', null)
            );
    }

    public function test_show_exposes_the_prediction_attention_summary(): void
    {
        $this->seed(WorldCup2026Seeder::class);
        $tournament = Tournament::firstOrFail();
        $user = User::factory()->create();
        Entry::factory()->for($tournament->pools()->where('slug', 'world-cup-2026-ffa')->firstOrFail())->for($user)->create();

        $this->actingAs($user)
            ->get(route('pools.show', 'world-cup-2026-ffa'))
            ->assertOk()
            ->assertInertia(fn (AssertableInertia $page) => $page
                ->where('attention.needs_attention', true)
                ->has('attention.open_windows', 1)
                ->where('attention.open_windows.0.phase_key', 'group')
                ->where('attention.open_windows.0.missing_count', 72)
                ->where('attention.open_windows.0.has_unresolved_ties', false)
                ->whereNot('attention.open_windows.0.deadline', null)
            );
    }

    public function test_show_exposes_the_open_join_window_to_a_non_member(): void
    {
        $this->seed(WorldCup2026Seeder::class);
        $this->actingAs(User::factory()->create());

        // A viewer who hasn't joined still gets the lock deadline + open join window, so the hero's
        // countdown band can invite them to join before predictions lock.
        $this->get(route('pools.show', 'world-cup-2026-ffa'))
            ->assertOk()
            ->assertInertia(fn (AssertableInertia $page) => $page
                ->where('pool.can_join', true)
                ->whereNot('pool.predictions_lock_at', null)
                ->where('standings.me', null)
            );
    }

    public function test_show_attention_is_empty_without_an_entry(): void
    {
        $this->seed(WorldCup2026Seeder::class);
        $this->actingAs(User::factory()->create());

        $this->get(route('pools.show', 'world-cup-2026-ffa'))
            ->assertOk()
            ->assertInertia(fn (AssertableInertia $page) => $page
                ->where('attention.needs_attention', false)
                ->where('attention.open_windows', [])
            );
    }

    public function test_pool_preview_and_player_directory_expose_avatar_urls(): void
    {
        $this->seed(WorldCup2026Seeder::class);
        $pool = Tournament::firstOrFail()->pools()->where('slug', 'world-cup-2026-ffa')->firstOrFail();

        $withPhoto = User::factory()->create(['avatar_path' => 'avatars/7/pic.jpg']);
        Entry::factory()->for($pool)->for($withPhoto)->create(['total_points' => 50]);

        $viewer = User::factory()->create();
        Entry::factory()->for($pool)->for($viewer)->create(['total_points' => 10]);

        $expected = Storage::disk('public')->url('avatars/7/pic.jpg');

        $this->actingAs($viewer)
            ->get(route('pools.show', 'world-cup-2026-ffa'))
            ->assertInertia(fn (AssertableInertia $page) => $page
                ->where('featuredBoards.0.top.0.avatar', $expected)
                ->where('featuredBoards.0.top.1.avatar', null)
                ->where('players.0.avatar', $expected)
                ->where('players.1.avatar', null)
            );
    }

    public function test_leaderboard_board_rows_expose_avatar_urls(): void
    {
        $this->seed(WorldCup2026Seeder::class);
        $pool = Tournament::firstOrFail()->pools()->where('slug', 'world-cup-2026-ffa')->firstOrFail();

        $user = User::factory()->create(['avatar_path' => 'avatars/3/a.png']);
        Entry::factory()->for($pool)->for($user)->create(['total_points' => 5]);

        $expected = Storage::disk('public')->url('avatars/3/a.png');

        $this->actingAs(User::factory()->create())
            ->get(route('pools.leaderboard', 'world-cup-2026-ffa'))
            ->assertInertia(fn (AssertableInertia $page) => $page
                // Overall board (index 0) and a standings board (index 1) both carry the avatar.
                ->where('boards.0.rows.0.avatar', $expected)
                ->where('boards.1.rows.0.avatar', $expected)
            );
    }

    public function test_show_surfaces_the_viewers_group_prediction(): void
    {
        $this->seed(WorldCup2026Seeder::class);
        $tournament = Tournament::firstOrFail();
        $user = User::factory()->create();
        $entry = Entry::factory()->for($tournament->pools()->where('slug', 'world-cup-2026-ffa')->firstOrFail())->for($user)->create();
        $fixture = $tournament->fixtures()->where('match_number', 1)->firstOrFail();

        GroupPrediction::factory()->create([
            'entry_id' => $entry->id,
            'fixture_id' => $fixture->id,
            'home_goals' => 2,
            'away_goals' => 1,
        ]);

        $this->actingAs($user);

        $this->get(route('pools.show', 'world-cup-2026-ffa'))
            ->assertInertia(fn (AssertableInertia $page) => $page
                ->where('groups.0.fixtures.0.prediction.home_goals', 2)
                ->where('groups.0.fixtures.0.prediction.away_goals', 1)
                ->where('groups.0.fixtures.0.prediction.points_awarded', null)
            );
    }

    public function test_show_exposes_the_settled_card_fields(): void
    {
        // Score review moved to the pool-agnostic admin area, so the pool page no longer carries an
        // admin flag — but the knockout fixtures still expose the fields a settled card needs.
        $this->seed(WorldCup2026Seeder::class);

        $this->actingAs(User::factory()->create())
            ->get(route('pools.show', 'world-cup-2026-ffa'))
            ->assertInertia(fn (AssertableInertia $page) => $page
                ->where('bracket.0.fixtures.0.winner_team_id', null)
                ->where('bracket.0.fixtures.0.home_penalties', null)
                ->where('bracket.0.fixtures.0.prediction', null)
            );
    }

    public function test_show_exposes_the_viewers_predicted_knockout_teams(): void
    {
        $this->seed(WorldCup2026Seeder::class);
        $tournament = Tournament::firstOrFail();
        $user = User::factory()->create();
        $entry = Entry::factory()->for($tournament->pools()->where('slug', 'world-cup-2026-ffa')->firstOrFail())->for($user)->create();

        $fixture = $tournament->knockoutFixtures()->orderBy('match_number')->first();
        [$home, $away] = Team::query()->take(2)->get()->all();

        KnockoutPrediction::create([
            'entry_id' => $entry->id,
            'fixture_id' => $fixture->id,
            'predicted_home_team_id' => $home->id,
            'predicted_away_team_id' => $away->id,
            'home_goals' => 2,
            'away_goals' => 1,
            'advancing_team_id' => $home->id,
        ]);

        $this->actingAs($user)
            ->get(route('pools.show', 'world-cup-2026-ffa'))
            ->assertInertia(fn (AssertableInertia $page) => $page
                ->where('bracket.0.fixtures.0.prediction.predicted_home.id', $home->id)
                ->where('bracket.0.fixtures.0.prediction.predicted_away.id', $away->id)
                ->whereNot('bracket.0.fixtures.0.prediction.predicted_home.flag_url', null)
            );
    }

    public function test_show_exposes_the_advancing_team_for_a_drawn_knockout_pick(): void
    {
        $this->seed(WorldCup2026Seeder::class);
        $tournament = Tournament::firstOrFail();
        $user = User::factory()->create();
        $entry = Entry::factory()->for($tournament->pools()->where('slug', 'world-cup-2026-ffa')->firstOrFail())->for($user)->create();

        $fixture = $tournament->knockoutFixtures()->orderBy('match_number')->first();
        [$home, $away] = Team::query()->take(2)->get()->all();

        // A drawn pick where the away team was chosen to advance (extra time / penalties).
        KnockoutPrediction::create([
            'entry_id' => $entry->id,
            'fixture_id' => $fixture->id,
            'predicted_home_team_id' => $home->id,
            'predicted_away_team_id' => $away->id,
            'home_goals' => 1,
            'away_goals' => 1,
            'advancing_team_id' => $away->id,
        ]);

        $this->actingAs($user)
            ->get(route('pools.show', 'world-cup-2026-ffa'))
            ->assertInertia(fn (AssertableInertia $page) => $page
                ->where('bracket.0.fixtures.0.prediction.home_goals', 1)
                ->where('bracket.0.fixtures.0.prediction.away_goals', 1)
                ->where('bracket.0.fixtures.0.prediction.advancing_team_id', $away->id)
            );
    }

    public function test_leaderboard_movement_is_measured_against_the_previous_matchday(): void
    {
        $this->seed(WorldCup2026Seeder::class);
        $tournament = Tournament::firstOrFail();
        $pool = $tournament->pools()->where('slug', 'world-cup-2026-ffa')->firstOrFail();

        $riser = User::factory()->create(['name' => 'Riser']);
        $riserEntry = Entry::factory()->for($pool)->for($riser)->create();
        $sinkerEntry = Entry::factory()->for($pool)->for(User::factory()->create(['name' => 'Sinker']))->create();

        $weakPartial = fn (int $home, int $away): array => $home < $away ? [2, 1] : [1, 2];
        $reverse = fn (int $home, int $away): array => $home < $away ? [0, 1] : [1, 0];

        // The riser trails after MD1 then overtakes in MD2; the sinker leads MD1 then stalls.
        $this->predictMatchday($riserEntry, $tournament, 'group-1', $weakPartial);
        $this->predictMatchday($riserEntry, $tournament, 'group-2', $this->seedOrderScores());
        $this->predictMatchday($sinkerEntry, $tournament, 'group-1', $this->seedOrderScores());
        $this->predictMatchday($sinkerEntry, $tournament, 'group-2', $reverse);

        $this->recordMatchdayResults($tournament, 'group-1', $this->seedOrderScores());
        $this->recordMatchdayResults($tournament, 'group-2', $this->seedOrderScores());
        (new ScoreEngine)->recompute($pool);

        // The current matchday (group-2) measures movement against the end of matchday 1.
        $this->actingAs($riser)
            ->get(route('pools.leaderboard', 'world-cup-2026-ffa'))
            ->assertInertia(fn (AssertableInertia $page) => $page
                ->where('selected_matchday', 'group-2')
                ->where('boards.0.key', 'overall')
                ->where('boards.0.rows.0.is_me', true)
                ->where('boards.0.rows.0.movement', 'up')
                ->where('boards.0.rows.0.movement_delta', 1)
                ->where('boards.0.rows.1.movement', 'down')
                ->where('boards.0.rows.1.movement_delta', 1)
            );

        // Travelling back to the first matchday: no prior round, so everyone shows as "new".
        $this->actingAs($riser)
            ->get(route('pools.leaderboard', ['pool' => 'world-cup-2026-ffa', 'matchday' => 'group-1']))
            ->assertInertia(fn (AssertableInertia $page) => $page
                ->where('selected_matchday', 'group-1')
                ->where('boards.0.rows.0.movement', 'new')
                ->where('boards.0.rows.0.movement_delta', null)
            );
    }

    public function test_show_includes_official_group_standings(): void
    {
        $this->seed(WorldCup2026Seeder::class);
        $this->actingAs(User::factory()->create());

        // Before any match is played the table is seed-ordered with everyone on zero.
        $this->get(route('pools.show', 'world-cup-2026-ffa'))
            ->assertOk()
            ->assertInertia(fn (AssertableInertia $page) => $page
                ->has('groups.0.standings', 4)
                ->where('groups.0.standings.0.rank', 1)
                ->where('groups.0.standings.0.played', 0)
                ->where('groups.0.standings.0.points', 0)
                ->whereNot('groups.0.standings.0.team', null)
                ->has('groups.0.standings.0.form', 0)
                ->where('groups.0.standings.3.rank', 4)
            );
    }

    public function test_group_standings_reflect_official_results(): void
    {
        $this->seed(WorldCup2026Seeder::class);
        $tournament = Tournament::firstOrFail();
        $groupA = $tournament->groups()->where('name', 'A')->firstOrFail();

        $positions = $groupA->teams()->get()->mapWithKeys(
            fn ($team): array => [$team->id => (int) $team->pivot->position],
        );

        // Official results: the better-seeded team (lower group position) wins 1–0 every match.
        foreach ($groupA->fixtures()->get() as $fixture) {
            $homeWins = $positions[$fixture->home_team_id] < $positions[$fixture->away_team_id];

            $fixture->update([
                'home_goals' => $homeWins ? 1 : 0,
                'away_goals' => $homeWins ? 0 : 1,
            ]);
        }

        $topTeamId = $positions->search(1, true);

        $this->actingAs(User::factory()->create());

        $this->get(route('pools.show', 'world-cup-2026-ffa'))
            ->assertInertia(fn (AssertableInertia $page) => $page
                ->where('groups.0.name', 'A')
                ->where('groups.0.standings.0.rank', 1)
                ->where('groups.0.standings.0.team.id', $topTeamId)
                ->where('groups.0.standings.0.played', 3)
                ->where('groups.0.standings.0.won', 3)
                ->where('groups.0.standings.0.points', 9)
                ->where('groups.0.standings.3.points', 0)
            );
    }

    public function test_show_exposes_the_scoring_strategy_and_how_to_play(): void
    {
        $this->seed(WorldCup2026Seeder::class);
        $this->actingAs(User::factory()->create());

        $this->get(route('pools.show', 'world-cup-2026-ffa'))
            ->assertOk()
            ->assertInertia(fn (AssertableInertia $page) => $page
                ->where('pool.scoring_strategy', 'upfront-bracket')
                ->where('pool.scoring_label', 'Upfront Bracket')
                ->whereNot('pool.scoring_description', null)
                ->whereNot('pool.how_to_play.summary', null)
                ->has('pool.how_to_play.steps')
                ->whereNot('pool.predictions_lock_at', null)
            );
    }

    public function test_show_and_leaderboard_expose_the_pool_identity(): void
    {
        $this->seed(WorldCup2026Seeder::class);
        $this->actingAs(User::factory()->create());

        // The pool's own name leads its identity; the source, tournament, accent and scoring style
        // follow as secondary context so sibling pools over the one tournament stay distinct.
        $identity = fn (AssertableInertia $page) => $page
            ->where('pool.name', 'Bolão Copa - FF&A')
            ->where('pool.source', 'Wagner Figueiredo')
            ->where('pool.tournament_name', 'World Cup 2026')
            ->where('pool.accent', 'pitch')
            ->where('pool.scoring_label', 'Upfront Bracket');

        $this->get(route('pools.show', 'world-cup-2026-ffa'))
            ->assertOk()
            ->assertInertia($identity);

        $this->get(route('pools.leaderboard', 'world-cup-2026-ffa'))
            ->assertOk()
            ->assertInertia($identity);
    }

    public function test_show_has_no_predicted_standings_without_an_entry(): void
    {
        $this->seed(WorldCup2026Seeder::class);
        $this->actingAs(User::factory()->create());

        $this->get(route('pools.show', 'world-cup-2026-ffa'))
            ->assertOk()
            ->assertInertia(fn (AssertableInertia $page) => $page
                ->where('groups.0.predicted_standings', null)
            );
    }

    public function test_show_includes_the_viewers_predicted_standings(): void
    {
        $this->seed(WorldCup2026Seeder::class);
        $tournament = Tournament::firstOrFail();
        $groupA = $tournament->groups()->where('name', 'A')->firstOrFail();
        $user = User::factory()->create();
        $entry = Entry::factory()->for($tournament->pools()->where('slug', 'world-cup-2026-ffa')->firstOrFail())->for($user)->create();

        $positions = $groupA->teams()->get()->mapWithKeys(
            fn ($team): array => [$team->id => (int) $team->pivot->position],
        );

        // The viewer predicts the better-seeded team to win 1–0 in every group A match.
        foreach ($groupA->fixtures()->get() as $fixture) {
            $homeWins = $positions[$fixture->home_team_id] < $positions[$fixture->away_team_id];

            GroupPrediction::factory()->create([
                'entry_id' => $entry->id,
                'fixture_id' => $fixture->id,
                'home_goals' => $homeWins ? 1 : 0,
                'away_goals' => $homeWins ? 0 : 1,
            ]);
        }

        $topTeamId = $positions->search(1, true);

        $this->actingAs($user);

        $this->get(route('pools.show', 'world-cup-2026-ffa'))
            ->assertOk()
            ->assertInertia(fn (AssertableInertia $page) => $page
                // The official table is still all zeros (no real results yet)...
                ->where('groups.0.standings.0.played', 0)
                // ...while the predicted table reflects the viewer's own picks.
                ->has('groups.0.predicted_standings', 4)
                ->where('groups.0.predicted_standings.0.rank', 1)
                ->where('groups.0.predicted_standings.0.team.id', $topTeamId)
                ->where('groups.0.predicted_standings.0.played', 3)
                ->where('groups.0.predicted_standings.0.won', 3)
                ->where('groups.0.predicted_standings.0.points', 9)
                ->where('groups.0.predicted_standings.3.points', 0)
            );
    }

    public function test_the_final_is_seeded_with_its_kick_off_date(): void
    {
        $this->seed(WorldCup2026Seeder::class);

        $final = Fixture::where('match_number', 104)->firstOrFail();

        $this->assertNotNull($final->kicks_off_at);
        $this->assertSame('2026-07-19', $final->kicks_off_at->toDateString());
    }

    public function test_show_includes_the_pool_summary(): void
    {
        $this->seed(WorldCup2026Seeder::class);
        $tournament = Tournament::firstOrFail();
        $user = User::factory()->create();
        Entry::factory()->for($tournament->pools()->where('slug', 'world-cup-2026-ffa')->firstOrFail())->for($user)->create();

        $this->actingAs($user);

        $this->get(route('pools.show', 'world-cup-2026-ffa'))
            ->assertOk()
            ->assertInertia(fn (AssertableInertia $page) => $page
                ->where('standings.participants', 1)
                ->where('standings.has_scores', false)
                ->where('standings.me.is_me', true)
                ->where('standings.me.primary_value', null)
                // The first three boards as full tables; with one entry the viewer is in the top, so
                // there's no separately-pinned "me" row. Overall is the prize board.
                ->has('featuredBoards', 3)
                ->where('featuredBoards.0.key', 'overall')
                ->where('featuredBoards.0.awards_prizes', true)
                ->has('featuredBoards.0.top', 1)
                ->where('featuredBoards.0.me', null)
                ->where('featuredBoards.1.key', 'match-winners')
                ->where('featuredBoards.1.awards_prizes', false)
                // No board beyond the first three today, so the "More leaderboards" section is empty.
                ->has('moreBoards', 0)
                // Board descriptors for the dialog (each carrying its tie-break stat + prize flag).
                ->has('pool.leaderboards', 3)
                ->where('pool.leaderboards.0.key', 'overall')
                ->where('pool.leaderboards.0.secondary_stat_label', null)
                ->where('pool.leaderboards.0.awards_prizes', true)
                ->where('pool.leaderboards.1.key', 'match-winners')
                ->where('pool.leaderboards.1.secondary_stat_label', 'Team goals')
                ->where('pool.leaderboards.1.awards_prizes', false)
            );
    }

    public function test_show_features_the_overall_table_and_condenses_side_boards(): void
    {
        $this->seed(WorldCup2026Seeder::class);
        $tournament = Tournament::firstOrFail();
        $pool = $tournament->pools()->where('slug', 'world-cup-2026-ffa')->firstOrFail();

        $leader = Entry::factory()->for($pool)
            ->for(User::factory()->create(['name' => 'Caller']))
            ->create(['total_points' => 90]);
        $me = User::factory()->create(['name' => 'Me']);
        $mine = Entry::factory()->for($pool)->for($me)->create(['total_points' => 40]);

        // Match Winners: Caller leads (12), I'm behind (3).
        LeaderboardStanding::factory()->for($leader)->create(['category' => LeaderboardCategory::MatchWinners, 'value' => 12]);
        LeaderboardStanding::factory()->for($mine)->create(['category' => LeaderboardCategory::MatchWinners, 'value' => 3]);

        $this->actingAs($me);

        $this->get(route('pools.show', 'world-cup-2026-ffa'))
            ->assertOk()
            ->assertInertia(fn (AssertableInertia $page) => $page
                // Match Winners is the second featured board, ranked by its standings.
                ->where('featuredBoards.1.key', 'match-winners')
                // A side board ships only its leader — the frontend renders it as a leader+you card.
                ->has('featuredBoards.1.top', 1)
                ->where('featuredBoards.1.top.0.name', 'Caller')
                ->where('featuredBoards.1.top.0.primary_value', 12)
                // The leader row keeps its entry id + is_me so it can be added to a comparison.
                ->where('featuredBoards.1.top.0.entry_id', $leader->id)
                ->where('featuredBoards.1.top.0.is_me', false)
                ->where('featuredBoards.1.participants', 2)
                // The viewer sits outside that single-row top, so they arrive via the pinned row.
                ->where('featuredBoards.1.me.name', 'You')
                ->where('featuredBoards.1.me.is_me', true)
                ->where('featuredBoards.1.me.primary_value', 3)
                ->has('moreBoards', 0)
            );
    }

    public function test_show_truncates_featured_boards_and_pins_the_viewer_when_outside_the_top(): void
    {
        $this->seed(WorldCup2026Seeder::class);
        $tournament = Tournament::firstOrFail();
        $pool = $tournament->pools()->where('slug', 'world-cup-2026-ffa')->firstOrFail();

        // Eleven ranked-above players, then the viewer last (12th) on the Overall board.
        foreach (range(1, 11) as $points) {
            Entry::factory()->for($pool)->for(User::factory()->create())->create(['total_points' => $points * 10]);
        }
        $me = User::factory()->create(['name' => 'Me']);
        Entry::factory()->for($pool)->for($me)->create(['total_points' => 5]);

        $this->actingAs($me);

        $this->get(route('pools.show', 'world-cup-2026-ffa'))
            ->assertOk()
            ->assertInertia(fn (AssertableInertia $page) => $page
                // The headline (Overall) board caps at 3 rows but reports the full pool size...
                ->has('featuredBoards.0.top', 3)
                ->where('featuredBoards.0.participants', 12)
                // ...and pins the viewer's own row since they rank outside the shown top.
                ->where('featuredBoards.0.me.rank', 12)
                ->where('featuredBoards.0.me.is_me', true)
            );
    }

    public function test_show_does_not_pin_the_viewer_when_inside_the_top(): void
    {
        $this->seed(WorldCup2026Seeder::class);
        $tournament = Tournament::firstOrFail();
        $pool = $tournament->pools()->where('slug', 'world-cup-2026-ffa')->firstOrFail();

        $me = User::factory()->create(['name' => 'Me']);
        Entry::factory()->for($pool)->for($me)->create(['total_points' => 100]);
        Entry::factory()->for($pool)->for(User::factory()->create())->create(['total_points' => 50]);

        $this->actingAs($me);

        $this->get(route('pools.show', 'world-cup-2026-ffa'))
            ->assertOk()
            ->assertInertia(fn (AssertableInertia $page) => $page
                ->has('featuredBoards.0.top', 2)
                ->where('featuredBoards.0.me', null)
            );
    }

    public function test_show_caps_a_secondary_featured_board_at_its_leader(): void
    {
        $this->seed(WorldCup2026Seeder::class);
        $tournament = Tournament::firstOrFail();
        $pool = $tournament->pools()->where('slug', 'world-cup-2026-ffa')->firstOrFail();

        // Six entries each with a Match Winners standing — a side board ships only its leader.
        foreach (range(1, 6) as $value) {
            $entry = Entry::factory()->for($pool)->for(User::factory()->create())->create(['total_points' => $value]);
            LeaderboardStanding::factory()->for($entry)->create(['category' => LeaderboardCategory::MatchWinners, 'value' => $value]);
        }

        $this->actingAs(User::factory()->create());

        $this->get(route('pools.show', 'world-cup-2026-ffa'))
            ->assertOk()
            ->assertInertia(fn (AssertableInertia $page) => $page
                ->where('featuredBoards.1.key', 'match-winners')
                ->has('featuredBoards.1.top', 1)
                ->where('featuredBoards.1.participants', 6)
            );
    }

    public function test_guests_are_redirected_from_the_leaderboard(): void
    {
        $this->get(route('pools.leaderboard', 'world-cup-2026-ffa'))
            ->assertRedirect(route('login'));
    }

    public function test_leaderboard_lists_pool_participants_without_scores(): void
    {
        $this->seed(WorldCup2026Seeder::class);
        $tournament = Tournament::firstOrFail();
        Entry::factory()->count(2)->for($tournament->pools()->where('slug', 'world-cup-2026-ffa')->firstOrFail())->create();
        $me = User::factory()->create();
        Entry::factory()->for($tournament->pools()->where('slug', 'world-cup-2026-ffa')->firstOrFail())->for($me)->create();

        $this->actingAs($me);

        $this->get(route('pools.leaderboard', 'world-cup-2026-ffa'))
            ->assertOk()
            ->assertInertia(fn (AssertableInertia $page) => $page
                ->component('pools/leaderboard')
                ->where('pool.slug', 'world-cup-2026-ffa')
                // Pricing is exposed so the Overall board can show inline prize amounts.
                ->where('pool.pricing.players', 3)
                ->has('pool.pricing.prizes')
                ->has('boards', 3)
                ->where('boards.0.key', 'overall')
                ->where('boards.0.awards_prizes', true)
                ->where('boards.1.awards_prizes', false)
                ->where('boards.0.has_scores', false)
                ->has('boards.0.rows', 3)
            );
    }

    public function test_leaderboard_ranks_the_overall_board_by_total_points(): void
    {
        $this->seed(WorldCup2026Seeder::class);
        $tournament = Tournament::firstOrFail();

        Entry::factory()->for($tournament->pools()->where('slug', 'world-cup-2026-ffa')->firstOrFail())
            ->for(User::factory()->create(['name' => 'Top Scorer']))
            ->create(['total_points' => 120]);

        $me = User::factory()->create(['name' => 'Runner Up']);
        Entry::factory()->for($tournament->pools()->where('slug', 'world-cup-2026-ffa')->firstOrFail())->for($me)->create(['total_points' => 40]);

        $this->actingAs($me);

        $this->get(route('pools.leaderboard', 'world-cup-2026-ffa'))
            ->assertOk()
            ->assertInertia(fn (AssertableInertia $page) => $page
                ->where('boards.0.key', 'overall')
                ->where('boards.0.has_scores', true)
                ->where('boards.0.rows.0.rank', 1)
                ->where('boards.0.rows.0.name', 'Top Scorer')
                ->where('boards.0.rows.0.primary_value', 120)
                ->where('boards.0.rows.1.rank', 2)
                ->where('boards.0.rows.1.name', 'You')
                ->where('boards.0.rows.1.is_me', true)
            );
    }

    public function test_leaderboard_ranks_a_category_board_by_its_standings(): void
    {
        $this->seed(WorldCup2026Seeder::class);
        $tournament = Tournament::firstOrFail();
        $pool = $tournament->pools()->where('slug', 'world-cup-2026-ffa')->firstOrFail();

        // The Overall leader trails on Match Winners, where a different player leads.
        $caller = Entry::factory()->for($pool)
            ->for(User::factory()->create(['name' => 'Caller']))
            ->create(['total_points' => 50]);
        $me = User::factory()->create(['name' => 'Me']);
        $mine = Entry::factory()->for($pool)->for($me)->create(['total_points' => 90]);

        LeaderboardStanding::factory()->for($caller)->create(['category' => LeaderboardCategory::Overall, 'value' => 50]);
        LeaderboardStanding::factory()->for($mine)->create(['category' => LeaderboardCategory::Overall, 'value' => 90]);
        LeaderboardStanding::factory()->for($caller)->create(['category' => LeaderboardCategory::MatchWinners, 'value' => 12, 'tiebreaker' => 30]);
        LeaderboardStanding::factory()->for($mine)->create(['category' => LeaderboardCategory::MatchWinners, 'value' => 3, 'tiebreaker' => 8]);

        $this->actingAs($me);

        $this->get(route('pools.leaderboard', 'world-cup-2026-ffa'))
            ->assertOk()
            ->assertInertia(fn (AssertableInertia $page) => $page
                ->where('boards.1.key', 'match-winners')
                ->where('boards.1.rows.0.name', 'Caller')
                ->where('boards.1.rows.0.primary_value', 12)
                ->where('boards.1.rows.0.secondary_value', 30)
                ->where('boards.1.rows.1.name', 'You')
                ->where('boards.1.rows.1.is_me', true)
            );
    }

    public function test_leaderboard_preselects_a_requested_board(): void
    {
        $this->seed(WorldCup2026Seeder::class);
        $tournament = Tournament::firstOrFail();
        $this->actingAs(User::factory()->create());

        $this->get(route('pools.leaderboard', ['pool' => 'world-cup-2026-ffa', 'board' => 'match-winners']))
            ->assertOk()
            ->assertInertia(fn (AssertableInertia $page) => $page
                ->where('active_board', 'match-winners')
            );

        // An unknown board falls back to no preselection (the page defaults to Overall).
        $this->get(route('pools.leaderboard', ['pool' => 'world-cup-2026-ffa', 'board' => 'nonsense']))
            ->assertOk()
            ->assertInertia(fn (AssertableInertia $page) => $page
                ->where('active_board', null)
            );
    }

    public function test_leaderboard_exposes_the_matchday_timeline_and_defaults_to_current(): void
    {
        [$me] = $this->seedScoredGroupStage();

        $this->actingAs($me);

        $this->get(route('pools.leaderboard', 'world-cup-2026-ffa'))
            ->assertOk()
            ->assertInertia(fn (AssertableInertia $page) => $page
                ->component('pools/leaderboard')
                // The whole group stage is settled, so matchday 3 is the current, default stop.
                ->where('selected_matchday', 'group-3')
                ->has('matchdays', 9)
                ->where('matchdays.0.key', 'group-1')
                ->where('matchdays.0.short_label', 'MD1')
                ->where('matchdays.2.key', 'group-3')
                ->where('matchdays.2.is_current', true)
                ->where('matchdays.3.key', 'round_of_32')
                ->where('matchdays.3.is_current', false)
                // Each board carries its per-matchday cards.
                ->has('boards.0.matchday_stats.you')
                ->has('boards.0.matchday_stats.top')
                ->has('boards.0.matchday_stats.lowest')
                ->has('boards.0.matchday_stats.biggest_climber')
                ->has('boards.0.matchday_stats.biggest_faller')
            );
    }

    public function test_leaderboard_travels_to_a_frozen_past_matchday(): void
    {
        [$me] = $this->seedScoredGroupStage();

        $this->actingAs($me);

        $this->get(route('pools.leaderboard', ['pool' => 'world-cup-2026-ffa', 'matchday' => 'group-1']))
            ->assertOk()
            ->assertInertia(fn (AssertableInertia $page) => $page
                ->where('selected_matchday', 'group-1')
                // Travelling back is a historical snapshot, not the live race.
                ->where('matchdays.0.is_current', false)
                ->where('boards.0.has_scores', true)
                ->where('boards.0.rows.0.is_me', true)
            );
    }

    public function test_leaderboard_falls_back_to_current_for_an_unplayed_matchday(): void
    {
        [$me] = $this->seedScoredGroupStage();

        $this->actingAs($me);

        // The final has not been played, so it cannot be travelled to yet.
        $this->get(route('pools.leaderboard', ['pool' => 'world-cup-2026-ffa', 'matchday' => 'final']))
            ->assertOk()
            ->assertInertia(fn (AssertableInertia $page) => $page
                ->where('selected_matchday', 'group-3')
            );
    }

    /**
     * Seed a pool whose entire group stage has been played out in seed order: the acting user tops
     * every board, a rival trails. Returns [me, rival] entries' users.
     *
     * @return array{0: User, 1: User}
     */
    private function seedScoredGroupStage(): array
    {
        $this->seed(WorldCup2026Seeder::class);
        $tournament = Tournament::firstOrFail();
        $pool = $tournament->pools()->where('slug', 'world-cup-2026-ffa')->firstOrFail();

        $me = User::factory()->create(['name' => 'Me']);
        $rival = User::factory()->create(['name' => 'Rival']);
        $mine = Entry::factory()->for($pool)->for($me)->create();
        $theirs = Entry::factory()->for($pool)->for($rival)->create();

        $this->predictAllGroups($mine, $tournament, $this->seedOrderScores());
        $this->predictAllGroups($theirs, $tournament, fn (int $home, int $away): array => $home < $away ? [0, 1] : [1, 0]);

        $this->recordOfficialGroupResults($tournament, $this->seedOrderScores());
        (new ScoreEngine)->recompute($pool);

        return [$me, $rival];
    }
}
