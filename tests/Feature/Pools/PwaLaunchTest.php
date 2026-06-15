<?php

namespace Tests\Feature\Pools;

use App\Models\Entry;
use App\Models\Pool;
use App\Models\Tournament;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Inertia\Testing\AssertableInertia;
use Tests\TestCase;

/**
 * Opening the installed PWA loads the manifest start_url (`/pools?source=pwa`). On that marker we
 * open the pool the player most recently joined instead of the pool picker, so relaunching the app
 * lands on a pool. The marker only ever rides the PWA launch — browser visits and the post-login
 * redirect reach `/pools` without it — so non-PWA flows are untouched. No client cache is involved.
 */
class PwaLaunchTest extends TestCase
{
    use RefreshDatabase;

    private function makePool(string $slug): Pool
    {
        return Pool::factory()->for(Tournament::factory()->inProgress())->create(['slug' => $slug]);
    }

    public function test_pwa_launch_opens_the_only_joined_pool(): void
    {
        $user = User::factory()->create();
        $pool = $this->makePool('my-pool');
        Entry::factory()->for($pool)->for($user)->create();

        $this->actingAs($user)
            ->get(route('pools.index', ['source' => 'pwa']))
            ->assertRedirect(route('pools.show', 'my-pool'));
    }

    public function test_pwa_launch_opens_the_most_recently_joined_pool(): void
    {
        $user = User::factory()->create();
        $older = $this->makePool('joined-first');
        $newer = $this->makePool('joined-last');

        // Explicit timestamps so the "most recently joined" pick can't flake on same-second entries.
        Entry::factory()->for($older)->for($user)->create(['created_at' => now()->subWeek()]);
        Entry::factory()->for($newer)->for($user)->create(['created_at' => now()]);

        $this->actingAs($user)
            ->get(route('pools.index', ['source' => 'pwa']))
            ->assertRedirect(route('pools.show', 'joined-last'));
    }

    public function test_pwa_launch_without_any_pool_shows_the_picker(): void
    {
        // A user who has joined nothing still needs the picker to pick one.
        $this->actingAs(User::factory()->create())
            ->get(route('pools.index', ['source' => 'pwa']))
            ->assertOk()
            ->assertInertia(fn (AssertableInertia $page) => $page->component('pools/index'));
    }

    public function test_a_non_pwa_visit_is_unaffected(): void
    {
        $user = User::factory()->create();
        $pool = $this->makePool('my-pool');
        Entry::factory()->for($pool)->for($user)->create();

        // Same membership, but no PWA marker (a browser open or the post-login redirect) — the
        // picker still renders, exactly as before this feature.
        $this->actingAs($user)
            ->get(route('pools.index'))
            ->assertOk()
            ->assertInertia(fn (AssertableInertia $page) => $page->component('pools/index'));
    }
}
