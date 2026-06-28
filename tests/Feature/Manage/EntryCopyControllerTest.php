<?php

namespace Tests\Feature\Manage;

use App\Enums\PhaseKey;
use App\Models\Entry;
use App\Models\Pool;
use App\Models\Tournament;
use App\Models\User;
use App\Notifications\PredictionsOverwrittenNotification;
use App\Services\Predictions\BracketResolver;
use Database\Seeders\WorldCup2026Seeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Notification;
use Inertia\Testing\AssertableInertia;
use Tests\Concerns\InteractsWithOfficialResults;
use Tests\Concerns\InteractsWithPredictions;
use Tests\TestCase;

class EntryCopyControllerTest extends TestCase
{
    use InteractsWithOfficialResults;
    use InteractsWithPredictions;
    use RefreshDatabase;

    private Tournament $tournament;

    private Pool $source;

    private Pool $phased;

    protected function setUp(): void
    {
        parent::setUp();

        $this->withoutVite();
        $this->seed(WorldCup2026Seeder::class);
        $this->tournament = Tournament::firstOrFail();
        $this->source = $this->tournament->pools()->where('slug', 'world-cup-2026-ffa')->firstOrFail();
        $this->phased = $this->tournament->pools()->where('slug', 'world-cup-2026-brothers')->firstOrFail();
    }

    public function test_non_admins_cannot_access_any_copy_endpoint(): void
    {
        $this->actingAs(User::factory()->create())
            ->get(route('manage.copy.create', $this->tournament))
            ->assertForbidden();

        $this->actingAs(User::factory()->create())
            ->post(route('manage.copy.preview', $this->tournament))
            ->assertForbidden();

        $this->actingAs(User::factory()->create())
            ->post(route('manage.copy.commit', $this->tournament))
            ->assertForbidden();
    }

    public function test_the_create_screen_lists_all_pools(): void
    {
        $this->actingAs($this->admin())
            ->get(route('manage.copy.create', $this->tournament))
            ->assertInertia(fn (AssertableInertia $page) => $page
                ->component('manage/copy-predictions')
                ->has('pools', 3)
                ->has('pools.0.scoring_label')
            );
    }

    public function test_preview_lists_source_pool_members_with_counts_and_flags(): void
    {
        $partial = $this->sourceMember(fn (Entry $entry) => $this->predictGroup($entry, $this->tournament, 'A', $this->seedOrderScores()));
        $full = $this->sourceMember(fn (Entry $entry) => $this->predictAllGroups($entry, $this->tournament, $this->seedOrderScores()));

        // $full also already has predictions in the destination → flagged as populated.
        $existing = $this->phased->entries()->create(['user_id' => $full->id]);
        $this->predictGroup($existing, $this->tournament, 'B', $this->seedOrderScores());

        $this->actingAs($this->admin())
            ->post(route('manage.copy.preview', $this->tournament), [
                'source_pool_id' => $this->source->id,
                'destination_pool_id' => $this->phased->id,
            ])
            ->assertInertia(fn (AssertableInertia $page) => $page
                ->component('manage/copy-predictions-review')
                ->where('source.id', $this->source->id)
                ->where('destination.id', $this->phased->id)
                // Upfront source into a phased destination → only the group stage transfers.
                ->where('knockout_transfers', false)
                ->where('candidates', function ($candidates) use ($partial, $full): bool {
                    $partialRow = collect($candidates)->firstWhere('user.id', $partial->id);
                    $fullRow = collect($candidates)->firstWhere('user.id', $full->id);

                    return $partialRow['group_count'] === 6
                        && $partialRow['destination_populated'] === false
                        && $fullRow['group_count'] === 72
                        && $fullRow['destination_populated'] === true;
                })
            );
    }

    public function test_preview_marks_knockout_transfers_true_between_two_upfront_pools(): void
    {
        $destination = $this->upfrontPool();

        $this->actingAs($this->admin())
            ->post(route('manage.copy.preview', $this->tournament), [
                'source_pool_id' => $this->source->id,
                'destination_pool_id' => $destination->id,
            ])
            ->assertInertia(fn (AssertableInertia $page) => $page
                ->component('manage/copy-predictions-review')
                ->where('knockout_transfers', true)
            );
    }

    public function test_preview_rejects_a_pool_from_another_tournament(): void
    {
        $foreign = Pool::factory()->create();

        $this->actingAs($this->admin())
            ->post(route('manage.copy.preview', $this->tournament), [
                'source_pool_id' => $foreign->id,
                'destination_pool_id' => $this->phased->id,
            ])
            ->assertSessionHasErrors('source_pool_id');
    }

    public function test_preview_rejects_when_source_equals_destination(): void
    {
        $this->actingAs($this->admin())
            ->post(route('manage.copy.preview', $this->tournament), [
                'source_pool_id' => $this->source->id,
                'destination_pool_id' => $this->source->id,
            ])
            ->assertSessionHasErrors('destination_pool_id');
    }

    public function test_commit_copies_the_group_stage_for_selected_users_and_rescores(): void
    {
        Notification::fake();
        $this->recordOfficialGroupResults($this->tournament, $this->seedOrderScores());

        $member = $this->sourceMember(fn (Entry $entry) => $this->predictAllGroups($entry, $this->tournament, $this->seedOrderScores()));

        $this->actingAs($this->admin())
            ->post(route('manage.copy.commit', $this->tournament), [
                'source_pool_id' => $this->source->id,
                'destination_pool_id' => $this->phased->id,
                'user_ids' => [$member->id],
            ])
            ->assertRedirect(route('manage.copy.create', $this->tournament));

        $entry = $this->phased->entryFor($member);
        $this->assertNotNull($entry, 'A destination entry was created for the imported player.');
        $this->assertSame(72, $entry->groupPredictions()->count());
        $this->assertNotNull($entry->refresh()->total_points);
        $this->assertGreaterThan(0, $entry->total_points);
        $this->assertTrue($entry->standings()->exists(), 'The destination was re-ranked.');

        // A fresh copy into an empty entry overwrites nothing, so the player is not emailed.
        Notification::assertNotSentTo($member, PredictionsOverwrittenNotification::class);
    }

    public function test_commit_copies_a_full_bracket_between_two_upfront_pools(): void
    {
        $destination = $this->upfrontPool();
        $member = $this->sourceMember(function (Entry $entry): void {
            $this->predictAllGroups($entry, $this->tournament, $this->seedOrderScores());
            $this->advanceAllHome($entry, new BracketResolver);
        });

        $this->actingAs($this->admin())
            ->post(route('manage.copy.commit', $this->tournament), [
                'source_pool_id' => $this->source->id,
                'destination_pool_id' => $destination->id,
                'user_ids' => [$member->id],
            ])
            ->assertRedirect();

        $final = $this->tournament->knockoutFixtures()
            ->whereRelation('phase', 'key', PhaseKey::Final->value)->firstOrFail();
        $sourceChampion = $this->source->entryFor($member)
            ->knockoutPredictions()->where('fixture_id', $final->id)->value('advancing_team_id');
        $destinationChampion = $destination->entryFor($member)
            ->knockoutPredictions()->where('fixture_id', $final->id)->value('advancing_team_id');

        $this->assertNotNull($sourceChampion);
        $this->assertSame($sourceChampion, $destinationChampion);
    }

    public function test_commit_copies_group_only_from_an_upfront_source_into_a_phased_destination(): void
    {
        $member = $this->sourceMember(function (Entry $entry): void {
            $this->predictAllGroups($entry, $this->tournament, $this->seedOrderScores());
            $this->advanceAllHome($entry, new BracketResolver);
        });

        $this->actingAs($this->admin())
            ->post(route('manage.copy.commit', $this->tournament), [
                'source_pool_id' => $this->source->id,
                'destination_pool_id' => $this->phased->id,
                'user_ids' => [$member->id],
            ])
            ->assertRedirect();

        $entry = $this->phased->entryFor($member);
        $this->assertSame(72, $entry->groupPredictions()->count());
        $this->assertSame(0, $entry->knockoutPredictions()
            ->where(fn ($query) => $query->whereNotNull('home_goals')->orWhereNotNull('advancing_team_id'))
            ->count());
    }

    public function test_commit_auto_creates_a_destination_entry_for_a_player_who_has_not_joined(): void
    {
        $member = $this->sourceMember(fn (Entry $entry) => $this->predictGroup($entry, $this->tournament, 'A', $this->seedOrderScores()));

        $this->assertNull($this->phased->entryFor($member));

        $this->actingAs($this->admin())
            ->post(route('manage.copy.commit', $this->tournament), [
                'source_pool_id' => $this->source->id,
                'destination_pool_id' => $this->phased->id,
                'user_ids' => [$member->id],
            ])
            ->assertRedirect();

        $this->assertNotNull($this->phased->entryFor($member));
    }

    public function test_commit_skips_a_populated_destination_without_overwrite(): void
    {
        Notification::fake();
        $member = $this->sourceMember(fn (Entry $entry) => $this->predictAllGroups($entry, $this->tournament, $this->seedOrderScores()));

        // The player already filled group A in the destination.
        $existing = $this->phased->entries()->create(['user_id' => $member->id]);
        $this->predictGroup($existing, $this->tournament, 'A', $this->seedOrderScores());

        $this->actingAs($this->admin())
            ->post(route('manage.copy.commit', $this->tournament), [
                'source_pool_id' => $this->source->id,
                'destination_pool_id' => $this->phased->id,
                'user_ids' => [$member->id],
            ])
            ->assertRedirect();

        // Untouched: still just the six group-A picks they had, nothing overwritten or emailed.
        $this->assertSame(6, $existing->groupPredictions()->count());
        Notification::assertNotSentTo($member, PredictionsOverwrittenNotification::class);
    }

    public function test_commit_overwrites_a_populated_destination_when_confirmed_and_notifies_the_player(): void
    {
        Notification::fake();
        $member = $this->sourceMember(fn (Entry $entry) => $this->predictAllGroups($entry, $this->tournament, $this->seedOrderScores()));
        $fresh = $this->sourceMember(fn (Entry $entry) => $this->predictAllGroups($entry, $this->tournament, $this->seedOrderScores()));

        $existing = $this->phased->entries()->create(['user_id' => $member->id]);
        $this->predictGroup($existing, $this->tournament, 'A', $this->seedOrderScores());

        $this->actingAs($this->admin())
            ->post(route('manage.copy.commit', $this->tournament), [
                'source_pool_id' => $this->source->id,
                'destination_pool_id' => $this->phased->id,
                'user_ids' => [$member->id, $fresh->id],
                'overwrite' => true,
            ])
            ->assertRedirect();

        // The populated entry is clean-replaced with the full 72-row group stage.
        $this->assertSame(72, $existing->groupPredictions()->count());

        // Only the player whose existing picks were replaced is told an organizer changed them.
        Notification::assertSentTo(
            $member,
            PredictionsOverwrittenNotification::class,
            fn (PredictionsOverwrittenNotification $notification): bool => $notification->pool->is($this->phased),
        );
        Notification::assertNotSentTo($fresh, PredictionsOverwrittenNotification::class);
    }

    public function test_commit_bypasses_a_closed_prediction_lock(): void
    {
        // A destination whose window is already shut — the admin tool writes into it anyway.
        $destination = $this->upfrontPool(now()->subDay());
        $this->assertFalse($destination->acceptsPredictions());

        $member = $this->sourceMember(fn (Entry $entry) => $this->predictAllGroups($entry, $this->tournament, $this->seedOrderScores()));

        $this->actingAs($this->admin())
            ->post(route('manage.copy.commit', $this->tournament), [
                'source_pool_id' => $this->source->id,
                'destination_pool_id' => $destination->id,
                'user_ids' => [$member->id],
            ])
            ->assertRedirect();

        $this->assertSame(72, $destination->entryFor($member)->groupPredictions()->count());
    }

    public function test_commit_rejects_a_user_who_is_not_a_source_member(): void
    {
        $stranger = User::factory()->create();

        $this->actingAs($this->admin())
            ->post(route('manage.copy.commit', $this->tournament), [
                'source_pool_id' => $this->source->id,
                'destination_pool_id' => $this->phased->id,
                'user_ids' => [$stranger->id],
            ])
            ->assertSessionHasErrors('user_ids.0');
    }

    /**
     * Create a user, join them to the source pool, and let the callback fill their source entry.
     */
    private function sourceMember(callable $fill): User
    {
        $user = User::factory()->create();
        $entry = $this->source->entries()->create(['user_id' => $user->id]);
        $fill($entry);

        return $user;
    }

    private function upfrontPool(\DateTimeInterface|string|null $lockAt = null): Pool
    {
        return Pool::factory()->create([
            'tournament_id' => $this->tournament->id,
            'predictions_lock_at' => $lockAt ?? now()->addWeek(),
        ]);
    }

    private function admin(): User
    {
        $admin = User::factory()->create();
        config()->set('admin.emails', [$admin->email]);

        return $admin;
    }
}
