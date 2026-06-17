<?php

namespace Tests\Feature;

use App\Models\Entry;
use App\Models\Pool;
use App\Models\User;
use App\Notifications\PlayerJoinedPoolNotification;
use Database\Seeders\WorldCup2026Seeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Notification;
use Inertia\Testing\AssertableInertia;
use Tests\TestCase;

class JoinPoolTest extends TestCase
{
    use RefreshDatabase;

    private Pool $pool;

    private User $user;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed(WorldCup2026Seeder::class);
        $this->pool = Pool::where('slug', 'world-cup-2026-ffa')->firstOrFail();
        $this->user = User::factory()->create();
    }

    public function test_guests_cannot_join(): void
    {
        $this->post(route('pools.join', 'world-cup-2026-ffa'))
            ->assertRedirect(route('login'));

        $this->assertDatabaseCount('entries', 0);
    }

    public function test_a_player_joins_and_an_entry_is_created(): void
    {
        $this->actingAs($this->user)
            ->post(route('pools.join', 'world-cup-2026-ffa'))
            ->assertRedirect(route('pools.show', 'world-cup-2026-ffa'));

        $this->assertDatabaseHas('entries', [
            'pool_id' => $this->pool->id,
            'user_id' => $this->user->id,
        ]);
        $this->assertDatabaseCount('entries', 1);
    }

    public function test_joining_is_idempotent(): void
    {
        $this->actingAs($this->user)->post(route('pools.join', 'world-cup-2026-ffa'));
        $this->actingAs($this->user)->post(route('pools.join', 'world-cup-2026-ffa'));

        $this->assertDatabaseCount('entries', 1);
    }

    public function test_a_new_join_notifies_each_admin_user(): void
    {
        Notification::fake();

        $admins = User::factory()->count(2)->create();
        config()->set('admin.emails', $admins->pluck('email')->all());

        $this->actingAs($this->user)->post(route('pools.join', 'world-cup-2026-ffa'));

        foreach ($admins as $admin) {
            Notification::assertSentTo(
                $admin,
                PlayerJoinedPoolNotification::class,
                fn (PlayerJoinedPoolNotification $notification): bool => $notification->pool->is($this->pool)
                    && $notification->player->is($this->user),
            );
        }
    }

    public function test_non_admins_are_not_notified(): void
    {
        Notification::fake();

        $admin = User::factory()->create();
        config()->set('admin.emails', [$admin->email]);

        $this->actingAs($this->user)->post(route('pools.join', 'world-cup-2026-ffa'));

        Notification::assertNotSentTo($this->user, PlayerJoinedPoolNotification::class);
    }

    public function test_re_joining_does_not_notify_again(): void
    {
        Notification::fake();

        $admin = User::factory()->create();
        config()->set('admin.emails', [$admin->email]);

        // firstOrCreate is idempotent, so a repeat join must not re-alert the organizers.
        $this->actingAs($this->user)->post(route('pools.join', 'world-cup-2026-ffa'));
        $this->actingAs($this->user)->post(route('pools.join', 'world-cup-2026-ffa'));

        Notification::assertSentToTimes($admin, PlayerJoinedPoolNotification::class, 1);
    }

    public function test_the_email_renders_with_the_buy_in_details(): void
    {
        $admin = User::factory()->create();
        $player = User::factory()->create(['name' => 'Ada Lovelace']);

        $mail = (new PlayerJoinedPoolNotification($this->pool, $player))->toMail($admin);

        $this->assertStringContainsString('Ada Lovelace', $mail->subject);

        [$htmlView] = $mail->view;
        $html = view($htmlView, $mail->viewData)->render();

        $this->assertStringContainsString('Ada Lovelace', $html);
        $this->assertStringContainsString($player->email, $html);
        $this->assertStringContainsString('BRL 50.00', $html);
    }

    public function test_the_email_handles_a_free_pool_with_no_buy_in(): void
    {
        $admin = User::factory()->create();
        $freePool = Pool::factory()->create(['entry_price' => 0]);

        $mail = (new PlayerJoinedPoolNotification($freePool, $this->user))->toMail($admin);

        [$htmlView] = $mail->view;
        $html = view($htmlView, $mail->viewData)->render();

        $this->assertStringContainsString('No buy-in (free pool)', $html);
    }

    public function test_joining_is_forbidden_after_predictions_lock(): void
    {
        $this->pool->update(['predictions_lock_at' => now()->subDay()]);

        $this->actingAs($this->user)
            ->post(route('pools.join', 'world-cup-2026-ffa'))
            ->assertForbidden();

        $this->assertDatabaseCount('entries', 0);
    }

    public function test_the_pool_page_exposes_pricing_and_the_join_window(): void
    {
        $this->actingAs($this->user)
            ->get(route('pools.show', 'world-cup-2026-ffa'))
            ->assertInertia(fn (AssertableInertia $page) => $page
                ->where('pool.can_join', true)
                ->where('pool.pricing.entry_price', 50)
                ->where('pool.pricing.currency', 'BRL')
                ->where('pool.pricing.house_fee_percentage', 10)
                ->has('pool.pricing.prizes', 3)
                ->where('pool.pricing.prizes.0.place', 1)
                ->where('pool.pricing.prizes.0.percentage', 70)
            );
    }

    public function test_the_index_computes_prize_amounts_from_the_pool(): void
    {
        // Three players in the FF&A pool: 3 × R$50 = R$150, less 10% fee = R$135.00 net.
        Entry::factory()->count(3)->for($this->pool)->create();

        $this->actingAs($this->user)
            ->get(route('pools.index'))
            ->assertInertia(fn (AssertableInertia $page) => $page
                ->where('pools.data.0.slug', 'world-cup-2026-ffa')
                ->where('pools.data.0.pricing.players', 3)
                ->where('pools.data.0.pricing.pot', 150)
                ->where('pools.data.0.pricing.net', 135)
                ->where('pools.data.0.pricing.prizes.0.amount', 94.5)
            );
    }

    public function test_the_index_flags_pools_the_player_has_joined(): void
    {
        $this->actingAs($this->user)->post(route('pools.join', 'world-cup-2026-ffa'));

        $this->actingAs($this->user)
            ->get(route('pools.index'))
            ->assertInertia(fn (AssertableInertia $page) => $page
                ->where('pools.data.0.slug', 'world-cup-2026-ffa')
                ->where('pools.data.0.joined', true)
                ->where('pools.data.1.slug', 'world-cup-2026-brothers')
                ->where('pools.data.1.joined', false)
            );
    }

    public function test_the_index_exposes_whether_joining_is_still_open(): void
    {
        // The card reads can_join to show the buy-in and a percentage prize split while the pool is
        // still filling, then switches to the now-final raw amounts once joining closes.
        $this->actingAs($this->user)
            ->get(route('pools.index'))
            ->assertInertia(fn (AssertableInertia $page) => $page
                ->where('pools.data.0.slug', 'world-cup-2026-ffa')
                ->where('pools.data.0.can_join', true)
            );

        $this->pool->update(['predictions_lock_at' => now()->subDay()]);

        $this->actingAs($this->user)
            ->get(route('pools.index'))
            ->assertInertia(fn (AssertableInertia $page) => $page
                ->where('pools.data.0.slug', 'world-cup-2026-ffa')
                ->where('pools.data.0.can_join', false)
            );
    }
}
