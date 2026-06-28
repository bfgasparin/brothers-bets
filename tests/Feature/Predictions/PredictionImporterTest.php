<?php

namespace Tests\Feature\Predictions;

use App\Enums\OrderingScope;
use App\Enums\PhaseKey;
use App\Models\Entry;
use App\Models\KnockoutPrediction;
use App\Models\Pool;
use App\Models\Tournament;
use App\Models\User;
use App\Services\Predictions\BracketResolver;
use App\Services\Predictions\OfficialBracketProjector;
use App\Services\Predictions\PredictionImporter;
use Database\Seeders\WorldCup2026Seeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Concerns\InteractsWithOfficialResults;
use Tests\Concerns\InteractsWithPredictions;
use Tests\TestCase;

class PredictionImporterTest extends TestCase
{
    use InteractsWithOfficialResults;
    use InteractsWithPredictions;
    use RefreshDatabase;

    private Tournament $tournament;

    private User $user;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed(WorldCup2026Seeder::class);
        $this->tournament = Tournament::firstOrFail();
        $this->user = User::factory()->create();
    }

    public function test_imports_group_scores_from_a_sibling_upfront_pool(): void
    {
        $source = $this->upfrontPool(now()->addWeek());
        $destination = $this->upfrontPool(now()->addWeek());

        $sourceEntry = $this->join($source);
        $destinationEntry = $this->join($destination);

        $this->predictAllGroups($sourceEntry, $this->tournament, $this->seedOrderScores());

        (new PredictionImporter)->import($destinationEntry, $source);

        // Every group prediction the source made is now on the destination, value-for-value.
        $this->assertSame(72, $destinationEntry->groupPredictions()->count());

        $sample = $this->tournament->groups()->where('name', 'A')->firstOrFail()
            ->fixtures()->orderBy('match_number')->firstOrFail();
        $sourcePick = $sourceEntry->groupPredictions()->where('fixture_id', $sample->id)->firstOrFail();
        $this->assertDatabaseHas('group_predictions', [
            'entry_id' => $destinationEntry->id,
            'fixture_id' => $sample->id,
            'home_goals' => $sourcePick->home_goals,
            'away_goals' => $sourcePick->away_goals,
        ]);
    }

    public function test_import_overwrites_existing_destination_scores_and_drops_extras(): void
    {
        $source = $this->upfrontPool(now()->addWeek());
        $destination = $this->upfrontPool(now()->addWeek());

        $sourceEntry = $this->join($source);
        $destinationEntry = $this->join($destination);

        // Source predicted only group A; the destination hand-filled groups A (differently) and B.
        $this->predictGroup($sourceEntry, $this->tournament, 'A', $this->seedOrderScores());
        $this->predictGroup($destinationEntry, $this->tournament, 'A', fn (): array => [3, 3]);
        $this->predictGroup($destinationEntry, $this->tournament, 'B', $this->seedOrderScores());

        (new PredictionImporter)->import($destinationEntry, $source);

        // Clean replace: the destination mirrors the source exactly — group A's six picks with the
        // source's scores, and the stray group B picks are gone.
        $this->assertSame(6, $destinationEntry->groupPredictions()->count());

        $groupBFixtureIds = $this->tournament->groups()->where('name', 'B')->firstOrFail()
            ->fixtures()->pluck('id');
        $this->assertSame(0, $destinationEntry->groupPredictions()->whereIn('fixture_id', $groupBFixtureIds)->count());

        $groupAFixture = $this->tournament->groups()->where('name', 'A')->firstOrFail()
            ->fixtures()->orderBy('match_number')->firstOrFail();
        $pick = $destinationEntry->groupPredictions()->where('fixture_id', $groupAFixture->id)->firstOrFail();
        $this->assertNotSame([3, 3], [$pick->home_goals, $pick->away_goals]);
    }

    public function test_import_copies_thirds_tie_orderings(): void
    {
        $source = $this->upfrontPool(now()->addWeek());
        $destination = $this->upfrontPool(now()->addWeek());

        $sourceEntry = $this->join($source);
        $destinationEntry = $this->join($destination);

        // seedOrderScores leaves all twelve third-placed teams level, so predictAllGroups records a
        // default thirds ordering to break the straddling cut — exactly the row that must travel.
        $this->predictAllGroups($sourceEntry, $this->tournament, $this->seedOrderScores());
        $sourceThirds = $sourceEntry->groupOrderings()->where('scope', OrderingScope::Thirds)->firstOrFail();

        (new PredictionImporter)->import($destinationEntry, $source);

        $destinationThirds = $destinationEntry->groupOrderings()->where('scope', OrderingScope::Thirds)->firstOrFail();
        $this->assertSame($sourceThirds->ordered_team_ids, $destinationThirds->ordered_team_ids);
    }

    public function test_import_reproduces_an_upfront_sources_full_bracket(): void
    {
        $source = $this->upfrontPool(now()->addWeek());
        $destination = $this->upfrontPool(now()->addWeek());

        $sourceEntry = $this->join($source);
        $destinationEntry = $this->join($destination);

        // Fully fill the source: predict every group, then ride the home team to a champion.
        $this->predictAllGroups($sourceEntry, $this->tournament, $this->seedOrderScores());
        $this->advanceAllHome($sourceEntry, new BracketResolver);

        (new PredictionImporter)->import($destinationEntry, $source);

        $final = $this->tournament->knockoutFixtures()
            ->whereRelation('phase', 'key', PhaseKey::Final->value)->firstOrFail();
        $sourceChampion = $sourceEntry->knockoutPredictions()->where('fixture_id', $final->id)->value('advancing_team_id');
        $destinationChampion = $destinationEntry->knockoutPredictions()->where('fixture_id', $final->id)->value('advancing_team_id');

        $this->assertNotNull($sourceChampion);
        $this->assertSame($sourceChampion, $destinationChampion);
    }

    public function test_imports_an_open_phased_knockout_round_from_a_sibling_phased_pool(): void
    {
        $this->projectRoundOf32();
        $this->setPhaseKickoff(PhaseKey::RoundOf32, now()->addDay()); // teams known, not kicked off

        $source = $this->phasedPool(now()->subDay());       // group locked
        $destination = $this->phasedPool(now()->subDay());
        $sourceEntry = $this->join($source);
        $destinationEntry = $this->join($destination);

        $this->fillRoundOf32($sourceEntry);

        (new PredictionImporter)->import($destinationEntry, $source);

        $r32 = $this->tournament->knockoutFixtures()
            ->whereRelation('phase', 'key', PhaseKey::RoundOf32->value)->firstOrFail()->fresh();

        $this->assertDatabaseHas('knockout_predictions', [
            'entry_id' => $destinationEntry->id,
            'fixture_id' => $r32->id,
            'home_goals' => 2,
            'away_goals' => 1,
            'advancing_team_id' => $r32->home_team_id,
            'predicted_home_team_id' => $r32->home_team_id,
            'predicted_away_team_id' => $r32->away_team_id,
        ]);

        // The locked group window is not imported, and only the open round was written (later
        // rounds are still pending) — never the whole bracket.
        $this->assertSame(0, $destinationEntry->groupPredictions()->count());
        $this->assertSame(16, $destinationEntry->knockoutPredictions()->count());
    }

    public function test_an_upfront_source_is_not_imported_into_a_phased_knockout_round(): void
    {
        $this->projectRoundOf32();
        $this->setPhaseKickoff(PhaseKey::RoundOf32, now()->addDay());

        $destination = $this->phasedPool(now()->subDay()); // group locked, R32 open
        $upfrontSource = $this->upfrontPool(now()->subDay()); // group lock passed → every phase locked

        $destinationEntry = $this->join($destination);
        $sourceEntry = $this->join($upfrontSource);

        // The upfront source has a complete bracket, but its window is shut: nothing is open in both.
        $this->predictAllGroups($sourceEntry, $this->tournament, $this->seedOrderScores());
        $this->advanceAllHome($sourceEntry, new BracketResolver);

        (new PredictionImporter)->import($destinationEntry, $upfrontSource);

        $this->assertSame(0, $destinationEntry->groupPredictions()->count());
        $this->assertSame(0, $destinationEntry->knockoutPredictions()->count());
    }

    public function test_lists_a_sibling_pool_with_predictions_as_an_eligible_source(): void
    {
        $destination = $this->upfrontPool(now()->addWeek());
        $source = $this->upfrontPool(now()->addWeek());
        $this->join($destination);
        $sourceEntry = $this->join($source);
        $this->predictGroup($sourceEntry, $this->tournament, 'A', $this->seedOrderScores());

        $sources = (new PredictionImporter)->eligibleSources($destination, $this->user);

        $this->assertCount(1, $sources);
        $this->assertSame($source->slug, $sources[0]['slug']);
        $this->assertSame($source->source, $sources[0]['source']);
        $this->assertSame($source->scoring_strategy->label(), $sources[0]['scoring_label']);
        $groupPhaseName = $this->tournament->phases()->where('key', PhaseKey::Group->value)->value('name');
        $this->assertSame([$groupPhaseName], $sources[0]['phase_labels']);
        $this->assertSame(6, $sources[0]['predictions_count']);
    }

    public function test_excludes_pools_that_cannot_be_imported_from(): void
    {
        $destination = $this->upfrontPool(now()->addWeek());
        $this->join($destination);

        // Joined sibling the acting user never predicted in → nothing to offer.
        $this->join($this->upfrontPool(now()->addWeek()));

        // Sibling the acting user never joined (another user predicted there) → not theirs to import.
        $notJoined = $this->upfrontPool(now()->addWeek());
        $other = User::factory()->create();
        $otherEntry = Entry::factory()->for($notJoined)->for($other)->create();
        $this->predictGroup($otherEntry, $this->tournament, 'A', $this->seedOrderScores());

        // A pool over a different tournament is not even a sibling.
        $foreignPool = Pool::factory()->create();
        Entry::factory()->for($foreignPool)->for($this->user)->create();

        $this->assertSame([], (new PredictionImporter)->eligibleSources($destination, $this->user));
    }

    public function test_suggests_when_the_open_window_is_empty_and_a_source_exists(): void
    {
        $destination = $this->upfrontPool(now()->addWeek());
        $source = $this->upfrontPool(now()->addWeek());
        $destinationEntry = $this->join($destination);
        $sourceEntry = $this->join($source);
        $this->predictAllGroups($sourceEntry, $this->tournament, $this->seedOrderScores());

        $importer = new PredictionImporter;
        $this->assertTrue($importer->shouldSuggest($destination, $this->user));

        // Once the user has started this window, stop suggesting.
        $this->predictGroup($destinationEntry, $this->tournament, 'A', $this->seedOrderScores());
        $this->assertFalse($importer->shouldSuggest($destination, $this->user));
    }

    public function test_does_not_suggest_without_an_eligible_source(): void
    {
        $destination = $this->upfrontPool(now()->addWeek());
        $this->join($destination);
        $this->join($this->upfrontPool(now()->addWeek())); // a sibling with no predictions

        $this->assertFalse((new PredictionImporter)->shouldSuggest($destination, $this->user));
    }

    public function test_the_admin_bypass_copies_a_closed_group_window_the_open_window_import_skips(): void
    {
        // Locks in the past → every prediction window is shut.
        $source = $this->upfrontPool(now()->subDay());
        $sourceEntry = $this->join($source);
        $this->predictAllGroups($sourceEntry, $this->tournament, $this->seedOrderScores());

        // The open-window import transfers nothing: neither pool has an open phase.
        $openDestination = $this->upfrontPool(now()->subDay());
        $openEntry = Entry::factory()->for($openDestination)->for($this->user)->create();
        (new PredictionImporter)->import($openEntry, $source);
        $this->assertSame(0, $openEntry->groupPredictions()->count());

        // The admin bypass copies the full group stage despite the closed lock.
        $bypassDestination = $this->upfrontPool(now()->subDay());
        $bypassEntry = Entry::factory()->for($bypassDestination)->for($this->user)->create();
        $this->assertTrue((new PredictionImporter)->importAllCompatible($bypassEntry, $source));
        $this->assertSame(72, $bypassEntry->groupPredictions()->count());
    }

    public function test_the_admin_bypass_reproduces_an_upfront_sources_full_bracket_through_closed_windows(): void
    {
        $source = $this->upfrontPool(now()->subDay());
        $destination = $this->upfrontPool(now()->subDay());

        $sourceEntry = $this->join($source);
        $destinationEntry = Entry::factory()->for($destination)->for($this->user)->create();

        $this->predictAllGroups($sourceEntry, $this->tournament, $this->seedOrderScores());
        $this->advanceAllHome($sourceEntry, new BracketResolver);

        $this->assertTrue((new PredictionImporter)->importAllCompatible($destinationEntry, $source));

        $final = $this->tournament->knockoutFixtures()
            ->whereRelation('phase', 'key', PhaseKey::Final->value)->firstOrFail();
        $sourceChampion = $sourceEntry->knockoutPredictions()->where('fixture_id', $final->id)->value('advancing_team_id');
        $destinationChampion = $destinationEntry->knockoutPredictions()->where('fixture_id', $final->id)->value('advancing_team_id');

        $this->assertNotNull($sourceChampion);
        $this->assertSame($sourceChampion, $destinationChampion);
    }

    public function test_the_admin_bypass_copies_group_only_from_an_upfront_source_into_an_official_bracket_destination(): void
    {
        $source = $this->upfrontPool(now()->subDay());
        $sourceEntry = $this->join($source);
        $this->predictAllGroups($sourceEntry, $this->tournament, $this->seedOrderScores());
        $this->advanceAllHome($sourceEntry, new BracketResolver);

        // A self-derived upfront bracket can't map onto an official knockout — both an official
        // (phased) and a per-match (rolling) destination take the group stage only.
        foreach ([$this->phasedPool(now()->subDay()), $this->rollingPool(now()->subDay())] as $destination) {
            $destinationEntry = Entry::factory()->for($destination)->for($this->user)->create();

            $this->assertTrue((new PredictionImporter)->importAllCompatible($destinationEntry, $source));

            $this->assertSame(72, $destinationEntry->groupPredictions()->count());
            $this->assertSame(0, $destinationEntry->knockoutPredictions()
                ->where(fn ($query) => $query->whereNotNull('home_goals')->orWhereNotNull('advancing_team_id'))
                ->count());
        }
    }

    public function test_the_admin_bypass_copies_an_official_knockout_round_between_two_phased_pools_through_a_closed_window(): void
    {
        $this->projectRoundOf32();
        $this->setPhaseKickoff(PhaseKey::RoundOf32, now()->subDay()); // teams known, window shut

        $source = $this->phasedPool(now()->subDay());
        $destination = $this->phasedPool(now()->subDay());
        $sourceEntry = $this->join($source);
        $destinationEntry = Entry::factory()->for($destination)->for($this->user)->create();

        $this->fillRoundOf32($sourceEntry);

        $this->assertTrue((new PredictionImporter)->importAllCompatible($destinationEntry, $source));

        $r32 = $this->tournament->knockoutFixtures()
            ->whereRelation('phase', 'key', PhaseKey::RoundOf32->value)->firstOrFail()->fresh();

        $this->assertDatabaseHas('knockout_predictions', [
            'entry_id' => $destinationEntry->id,
            'fixture_id' => $r32->id,
            'home_goals' => 2,
            'away_goals' => 1,
            'advancing_team_id' => $r32->home_team_id,
            'predicted_home_team_id' => $r32->home_team_id,
            'predicted_away_team_id' => $r32->away_team_id,
        ]);

        // Only the predicted round travels (the source filled R32 only), with official participants.
        $this->assertSame(16, $destinationEntry->knockoutPredictions()->count());
    }

    public function test_the_admin_bypass_is_a_noop_without_a_source_entry(): void
    {
        $source = $this->upfrontPool(now()->subDay());
        $destination = $this->upfrontPool(now()->subDay());
        $destinationEntry = $this->join($destination); // the user never joined the source

        $this->assertFalse((new PredictionImporter)->importAllCompatible($destinationEntry, $source));
        $this->assertSame(0, $destinationEntry->groupPredictions()->count());
    }

    public function test_the_admin_bypass_is_a_noop_when_the_source_has_no_predictions(): void
    {
        $source = $this->upfrontPool(now()->subDay());
        $destination = $this->upfrontPool(now()->subDay());
        $this->join($source); // joined the source, but predicted nothing
        $destinationEntry = $this->join($destination);

        $this->assertFalse((new PredictionImporter)->importAllCompatible($destinationEntry, $source));
        $this->assertSame(0, $destinationEntry->groupPredictions()->count());
    }

    public function test_the_admin_bypass_clears_a_prior_authored_bracket_flag(): void
    {
        $source = $this->upfrontPool(now()->subDay());
        $destination = $this->upfrontPool(now()->subDay());
        $sourceEntry = $this->join($source);
        $destinationEntry = $this->join($destination);
        $destinationEntry->authored_bracket_at = now();
        $destinationEntry->save();

        $this->predictAllGroups($sourceEntry, $this->tournament, $this->seedOrderScores());

        $this->assertTrue((new PredictionImporter)->importAllCompatible($destinationEntry, $source));
        $this->assertNull($destinationEntry->refresh()->authored_bracket_at);
    }

    public function test_has_own_predictions_detects_real_picks_only(): void
    {
        $pool = $this->upfrontPool(now()->addWeek());
        $entry = $this->join($pool);
        $importer = new PredictionImporter;

        $this->assertFalse($importer->hasOwnPredictions($entry));

        $this->predictGroup($entry, $this->tournament, 'A', $this->seedOrderScores());
        $this->assertTrue($importer->hasOwnPredictions($entry->refresh()));
    }

    private function fillRoundOf32(Entry $entry): void
    {
        $fixtures = $this->tournament->knockoutFixtures()
            ->whereRelation('phase', 'key', PhaseKey::RoundOf32->value)->get();

        foreach ($fixtures as $fixture) {
            KnockoutPrediction::create([
                'entry_id' => $entry->id,
                'fixture_id' => $fixture->id,
                'home_goals' => 2,
                'away_goals' => 1,
                'advancing_team_id' => $fixture->home_team_id,
                'predicted_home_team_id' => $fixture->home_team_id,
                'predicted_away_team_id' => $fixture->away_team_id,
            ]);
        }
    }

    private function upfrontPool(\DateTimeInterface|string|null $lockAt): Pool
    {
        return Pool::factory()->create([
            'tournament_id' => $this->tournament->id,
            'predictions_lock_at' => $lockAt,
        ]);
    }

    private function phasedPool(\DateTimeInterface|string|null $lockAt): Pool
    {
        return Pool::factory()->phasedBracket()->create([
            'tournament_id' => $this->tournament->id,
            'predictions_lock_at' => $lockAt,
        ]);
    }

    private function rollingPool(\DateTimeInterface|string|null $lockAt): Pool
    {
        return Pool::factory()->rollingBracket()->create([
            'tournament_id' => $this->tournament->id,
            'predictions_lock_at' => $lockAt,
        ]);
    }

    private function join(Pool $pool): Entry
    {
        return Entry::factory()->for($pool)->for($this->user)->create();
    }

    private function projectRoundOf32(): void
    {
        $this->recordOfficialGroupResults($this->tournament, $this->seedOrderScores());
        (new OfficialBracketProjector)->project($this->tournament);
    }

    private function setPhaseKickoff(PhaseKey $key, \DateTimeInterface $when): void
    {
        $this->tournament->phases()->where('key', $key->value)->firstOrFail()
            ->fixtures()->update(['kicks_off_at' => $when]);
    }
}
