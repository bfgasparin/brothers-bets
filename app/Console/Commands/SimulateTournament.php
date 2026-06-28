<?php

namespace App\Console\Commands;

use App\Enums\FixtureStatus;
use App\Enums\PhaseKey;
use App\Enums\ProposalStatus;
use App\Models\Entry;
use App\Models\EntryGroupOrdering;
use App\Models\Fixture;
use App\Models\FixtureLiveState;
use App\Models\GroupPrediction;
use App\Models\KnockoutPrediction;
use App\Models\LeaderboardStanding;
use App\Models\Phase;
use App\Models\Pool;
use App\Models\ScoreBatch;
use App\Models\ScoreProposal;
use App\Models\Tournament;
use App\Models\User;
use App\Services\Live\GoLive;
use App\Services\Predictions\BracketResolver;
use App\Services\Predictions\DefaultTieOrdering;
use App\Services\Predictions\OfficialBracketProjector;
use App\Services\Scoring\RankSnapshotter;
use App\Services\Scoring\ScoreEngine;
use App\Support\DeterministicScores;
use App\Support\DevClock;
use Carbon\CarbonImmutable;
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;
use Illuminate\Support\Collection;

#[Signature('tournament:simulate
    {slug=world-cup-2026 : The tournament slug}
    {--players=8 : How many demo players to fabricate}
    {--me=test@example.com : Also give this user an entry so you appear on the board}
    {--through=final : Play official results through this phase (group|round_of_32|round_of_16|quarter_finals|semi_finals|third_place|final)}
    {--until= : Play official results for matches ended on or before this datetime (UTC), e.g. "2026-06-18 22:00" — like --through but date-granular, so you can land mid-phase. Also advances the dev clock (local)}
    {--seed= : Optional seed string to generate a different but reproducible world}
    {--predict-only : Set up players + predictions only; leave fixtures scheduled with no official results}
    {--me-skip= : Leave the --me user joined but WITHOUT predictions in this pool (by slug or source), so the "import from another pool" suggestion shows when you open it — its sibling, which the user fills, becomes the source}
    {--tie= : Stage an UNRESOLVED official tie for an admin to resolve on the review screen — "thirds" (a best-thirds cut tie) or "group" (every group level). Skips the normal results play.}
    {--player-tie= : Leave players with an UNRESOLVED PREDICTED tie so the player tie-resolution UI shows — "thirds" gives the --me user seed-order wins so the best-thirds cut ties, "group" gives all-level group scores so every group ties. Auto-resolution is disabled for everyone, so demo players are left incomplete too. Pair with --predict-only to inspect the predict page before the lock.}
    {--reset : Clear official results + scoring for the tournament before simulating}')]
#[Description('Locally simulate a closed, fully-scored tournament: demo players + predictions, official results, computed board.')]
class SimulateTournament extends Command
{
    private DeterministicScores $scores;

    /** Pool id in which the --me user is deliberately left empty (see --me-skip), or null. */
    private ?int $meSkipPoolId = null;

    /**
     * Each pool's demo entries paired with their prediction seed index, keyed by pool id. Built up
     * front so {@see playResults()} can fill a phased pool's per-round knockout picks as the official
     * participants are projected.
     *
     * @var array<int, Collection<int, array{0: Entry, 1: int}>>
     */
    private array $rosterByPool = [];

    public function handle(
        BracketResolver $resolver,
        OfficialBracketProjector $projector,
        ScoreEngine $engine,
        RankSnapshotter $snapshotter,
        DefaultTieOrdering $defaultTieOrdering,
    ): int {
        $tournament = Tournament::where('slug', $this->argument('slug'))->first();

        if ($tournament === null) {
            $this->components->error("Tournament [{$this->argument('slug')}] not found. Run `php artisan db:seed` first.");

            return self::FAILURE;
        }

        // The competition holds the structure and official results; entries and scoring belong to
        // the pools played over it. Simulate every pool (e.g. the upfront- and phased-bracket pools)
        // so each gets its own players, predictions and scored board.
        $tournament->load('pools');
        $pools = $tournament->pools;

        if ($pools->isEmpty()) {
            $this->components->error("Tournament [{$this->argument('slug')}] has no pool to simulate. Run `php artisan db:seed` first.");

            return self::FAILURE;
        }

        $primary = $pools->first();

        $through = PhaseKey::tryFrom((string) $this->option('through'));

        if ($through === null) {
            $this->components->error("Unknown phase [{$this->option('through')}]. Use one of: ".implode(', ', array_map(fn (PhaseKey $key): string => $key->value, PhaseKey::cases())));

            return self::FAILURE;
        }

        $tie = $this->option('tie');

        if ($tie !== null && ! in_array($tie, ['thirds', 'group'], true)) {
            $this->components->error("Unknown --tie [{$tie}]. Use one of: thirds, group.");

            return self::FAILURE;
        }

        $playerTie = $this->option('player-tie');

        if ($playerTie !== null && ! in_array($playerTie, ['thirds', 'group'], true)) {
            $this->components->error("Unknown --player-tie [{$playerTie}]. Use one of: thirds, group.");

            return self::FAILURE;
        }

        $until = ($untilOption = $this->option('until')) !== null
            ? CarbonImmutable::parse($untilOption, 'UTC')
            : null;

        if (($meSkip = $this->option('me-skip')) !== null) {
            $meSkipPool = $pools->first(fn (Pool $pool): bool => strcasecmp($pool->slug, $meSkip) === 0
                || strcasecmp($pool->source, $meSkip) === 0);

            if ($meSkipPool === null) {
                $this->components->error("Unknown --me-skip [{$meSkip}]. Use a pool slug or source: ".$pools->map(fn (Pool $pool): string => $pool->slug)->implode(', '));

                return self::FAILURE;
            }

            if ($pools->count() < 2) {
                $this->components->warn('Only one pool exists, so there is no sibling to import from — the suggestion will not appear.');
            }

            $this->meSkipPoolId = $meSkipPool->id;
        }

        $this->scores = new DeterministicScores((string) ($this->option('seed') ?? ''));

        if ($this->option('reset')) {
            $this->reset($tournament);
            $this->components->info('Cleared previous results and scoring.');
        }

        foreach ($pools as $pool) {
            $entries = $this->ensurePlayers($pool);
            $this->rosterByPool[$pool->id] = $entries;

            foreach ($entries as [$entry, $seedIndex]) {
                // Leave the --me user (seed index 0) empty in the chosen pool so the import
                // suggestion has a sibling to offer when that pool's predict page is opened.
                if ($this->isMeSkipped($pool, $seedIndex)) {
                    continue;
                }

                // Respect predictions already made in the UI (e.g. your own); only fill empties.
                if ($entry->groupPredictions()->exists()) {
                    continue;
                }

                // With --player-tie, give the --me user (seed index 0) deliberate tie-producing
                // scores of the requested kind so the player tie-resolution UI reliably shows;
                // everyone else keeps the normal random scores.
                if ($playerTie !== null && $seedIndex === 0) {
                    $this->generateTiedGroupPredictions($tournament, $entry, $playerTie);
                } else {
                    $this->generateGroupPredictions($tournament, $entry, $seedIndex);
                }

                // Upfront pools derive the whole bracket from group picks now. Phased pools leave the
                // knockout rounds to be predicted against the official teams as they are projected
                // (see playResults), so there is nothing to resolve up front for them.
                if ($pool->predictsKnockoutBracket()) {
                    // No human to break ties in a simulation: fall back to the deterministic default
                    // order so the self-derived bracket resolves fully before knockout picks. With
                    // --player-tie this is skipped for everyone, so the --me deliberate tie and any
                    // demo player's natural tie are left unresolved (the bracket fills as far as it
                    // resolves) for testing the tie-resolution UI and incomplete-board scenarios.
                    if ($playerTie === null) {
                        $defaultTieOrdering->applyToEntry($entry);
                    }

                    $this->generateKnockoutPredictions($tournament, $entry, $seedIndex, $resolver);
                }
            }
        }

        $this->components->info('Players ready; predictions generated.');

        $predictOnly = (bool) $this->option('predict-only');

        $this->clearPredictionsLockOverride($tournament);

        if ($tie !== null) {
            $this->stageUnresolvedTie($tournament, $primary, $tie);
            $tournament->syncStatus();

            return self::SUCCESS;
        }

        if (! $predictOnly) {
            $this->playResults($tournament, $through, $until, $projector, $engine, $snapshotter, $defaultTieOrdering);
        }

        if ($until !== null) {
            $this->advanceTo($tournament, $until);
        } elseif (! $predictOnly) {
            // Move the simulated clock just past the matches we played, so the world is coherent and
            // the phased pool's per-round knockout windows lock — otherwise compare would hide other
            // players' knockout picks behind the "reveals after lock" gate.
            $lastKickoff = $tournament->fixtures()->whereNotNull('home_goals')->max('kicks_off_at');

            if ($lastKickoff !== null) {
                $this->advanceTo(
                    $tournament,
                    CarbonImmutable::parse($lastKickoff, 'UTC')->addMinutes((int) config('scoring.match_duration_minutes') + 1),
                );
            }
        }

        // The simulated results are in place; derive the lifecycle status from the fixtures.
        $tournament->syncStatus();

        $this->summarise($pools, $through, $predictOnly, $until);

        return self::SUCCESS;
    }

    /**
     * Stage an UNRESOLVED official tie as an open batch of proposals — the one state the normal
     * simulation auto-resolves past — so an admin can order the tied teams on the review screen.
     * "thirds" leaves the best-thirds cut tied (seed order resolves every group cleanly but leaves
     * all twelve thirds level); "group" leaves every group level (all goalless draws). Proposals
     * alone surface the fixtures on the review screen, so this touches no fixture/schedule state.
     */
    private function stageUnresolvedTie(Tournament $tournament, Pool $pool, string $tie): void
    {
        $rule = $this->tieRule($tie);

        $batch = ScoreBatch::openFor($tournament);

        $proposals = 0;

        foreach ($tournament->groups()->with(['teams', 'fixtures'])->orderBy('sort_order')->get() as $group) {
            $positions = $group->teams->mapWithKeys(
                fn ($team): array => [$team->id => (int) $team->pivot->position],
            );

            foreach ($group->fixtures as $fixture) {
                [$home, $away] = $rule($positions[$fixture->home_team_id], $positions[$fixture->away_team_id]);

                ScoreProposal::updateOrCreate(
                    ['score_batch_id' => $batch->id, 'fixture_id' => $fixture->id],
                    [
                        'home_goals' => $home,
                        'away_goals' => $away,
                        'winner_team_id' => $home === $away
                            ? null
                            : ($home > $away ? $fixture->home_team_id : $fixture->away_team_id),
                        'status' => ProposalStatus::Pending,
                    ],
                );

                $proposals++;
            }
        }

        $this->reportStagedTie($pool, $tie, $proposals);
    }

    /**
     * Tell the operator what was staged and how to drive the admin tie-resolution flow.
     */
    private function reportStagedTie(Pool $pool, string $tie, int $proposals): void
    {
        $me = (string) $this->option('me');

        $description = $tie === 'group'
            ? 'every group is level (all goalless draws) — order who finishes 1st/2nd/3rd in each'
            : 'the best third-placed teams are tied across the qualifying cut — order which thirds classify';

        $this->newLine();
        $this->components->info("Staged {$proposals} group results as an open batch with an UNRESOLVED tie: {$description}.");

        if ($me !== '') {
            $admin = User::firstWhere('email', $me);

            if ($admin === null || ! $admin->isAdmin()) {
                $this->components->warn("Add {$me} to ADMIN_EMAILS in .env so it can open the review screen (it is behind the admin gate).");
            }
        }

        $this->components->info('Next: log in at /login as '.($me !== '' ? $me : 'an admin').' (the 6-digit code is written to the log — `php artisan pail`), open '.route('manage.scores.review', $pool->tournament).' — the "Resolve tied teams" section lists the tied teams. Drag them into order, then Approve & publish; the bracket projects and the board updates.');
    }

    /**
     * Reset the tournament to a fully unplayed state: clear official results, live scoreboards,
     * projected knockout participants, every pool's predictions, tie orderings, leaderboard
     * standings and computed totals/ranks, and any score batches. Clearing the predictions (not
     * just results) is what lets a re-run regenerate them — otherwise the generators skip entries
     * that already have picks, so a changed generation rule (e.g. injecting drawn knockout scores)
     * would never take effect.
     */
    private function reset(Tournament $tournament): void
    {
        $tournament->fixtures()->update([
            'home_goals' => null,
            'away_goals' => null,
            'winner_team_id' => null,
            'home_penalties' => null,
            'away_penalties' => null,
            'status' => FixtureStatus::Scheduled->value,
        ]);

        // Drop any live scoreboards so a re-run starts the Live Center fresh; otherwise stale
        // boards survive against reset fixtures, mirroring Fixture::reschedule()'s liveState delete.
        FixtureLiveState::whereIn('fixture_id', $tournament->fixtures()->pluck('id'))->delete();

        Fixture::whereIn('id', $tournament->knockoutFixtures()->pluck('id'))
            ->update(['home_team_id' => null, 'away_team_id' => null]);

        foreach ($tournament->pools as $pool) {
            $entryIds = $pool->entries()->pluck('id');

            GroupPrediction::whereIn('entry_id', $entryIds)->delete();
            KnockoutPrediction::whereIn('entry_id', $entryIds)->delete();
            EntryGroupOrdering::whereIn('entry_id', $entryIds)->delete();
            LeaderboardStanding::whereIn('entry_id', $entryIds)->delete();

            $pool->entries()->update([
                'total_points' => null,
                'rank' => null,
                'previous_rank' => null,
            ]);
        }

        $tournament->scoreBatches()->delete();
    }

    /**
     * Ensure demo players and the --me user each have a submitted entry in the pool.
     *
     * @return Collection<int, array{0: Entry, 1: int}> entry + its prediction seed index
     */
    private function ensurePlayers(Pool $pool): Collection
    {
        $entries = collect();

        for ($i = 1; $i <= (int) $this->option('players'); $i++) {
            $user = User::updateOrCreate(
                ['email' => "sim-player-{$i}@ffa.test"],
                ['name' => "Player {$i}", 'email_verified_at' => now()],
            );

            $entries->push([$this->ensureEntry($pool, $user), $i]);
        }

        $meEmail = (string) $this->option('me');

        if ($meEmail !== '') {
            $me = User::firstWhere('email', $meEmail) ?? User::updateOrCreate(
                ['email' => $meEmail],
                ['name' => 'You', 'email_verified_at' => now()],
            );

            $entries->push([$this->ensureEntry($pool, $me), 0]);
        }

        return $entries;
    }

    private function ensureEntry(Pool $pool, User $user): Entry
    {
        return $pool->entries()->firstOrCreate(['user_id' => $user->id]);
    }

    /**
     * Whether this entry is the --me user (seed index 0) in the pool chosen by --me-skip, and so
     * should be left without generated predictions.
     */
    private function isMeSkipped(Pool $pool, int $seedIndex): bool
    {
        return $seedIndex === 0 && $pool->id === $this->meSkipPoolId;
    }

    private function generateGroupPredictions(Tournament $tournament, Entry $entry, int $seedIndex): void
    {
        foreach ($tournament->groups()->with('fixtures')->get() as $group) {
            foreach ($group->fixtures as $fixture) {
                GroupPrediction::updateOrCreate(
                    ['entry_id' => $entry->id, 'fixture_id' => $fixture->id],
                    [
                        'home_goals' => $this->goals($seedIndex, $fixture->match_number, 'ph'),
                        'away_goals' => $this->goals($seedIndex, $fixture->match_number, 'pa'),
                    ],
                );
            }
        }
    }

    /**
     * Give one entry deliberate tie-producing group predictions of the requested kind (the player
     * analogue of {@see stageUnresolvedTie}): "group" makes every group level (all goalless draws);
     * "thirds" makes the better seed win every match so the groups resolve cleanly but all twelve
     * thirds tie across the qualifying cut. Paired with skipping the default tie ordering, this
     * leaves the entry's bracket blocked on a tie the player must resolve by hand.
     */
    private function generateTiedGroupPredictions(Tournament $tournament, Entry $entry, string $tie): void
    {
        $rule = $this->tieRule($tie);

        foreach ($tournament->groups()->with(['teams', 'fixtures'])->get() as $group) {
            $positions = $group->teams->mapWithKeys(
                fn ($team): array => [$team->id => (int) $team->pivot->position],
            );

            foreach ($group->fixtures as $fixture) {
                [$home, $away] = $rule($positions[$fixture->home_team_id], $positions[$fixture->away_team_id]);

                GroupPrediction::updateOrCreate(
                    ['entry_id' => $entry->id, 'fixture_id' => $fixture->id],
                    ['home_goals' => $home, 'away_goals' => $away],
                );
            }
        }
    }

    /**
     * The deterministic scoreline rule that produces an unresolved tie of the given kind, shared by
     * the official tie staging ({@see stageUnresolvedTie}) and the per-player tie generation
     * ({@see generateTiedGroupPredictions}). "group" draws every match 0-0 so each group is fully
     * level; "thirds" lets the better seed win 1-0 so the groups resolve cleanly but every third is
     * identical, tying the best-thirds cut.
     *
     * @return callable(int, int): array{0: int, 1: int} home seed, away seed => [home goals, away goals]
     */
    private function tieRule(string $tie): callable
    {
        return $tie === 'group'
            ? fn (int $home, int $away): array => [0, 0]
            : fn (int $home, int $away): array => $home < $away ? [1, 0] : [0, 1];
    }

    /**
     * Fill the entry's knockout picks round by round, re-persisting so each round's advancing
     * picks resolve the next round's participants (the app-side analogue of advanceAllHome).
     */
    private function generateKnockoutPredictions(Tournament $tournament, Entry $entry, int $seedIndex, BracketResolver $resolver): void
    {
        $matchNumbers = $tournament->knockoutFixtures()->pluck('match_number', 'id');

        $resolver->persist($entry);

        for ($round = 0; $round < 6; $round++) {
            $entry->load('knockoutPredictions');
            $progressed = false;

            foreach ($entry->knockoutPredictions as $prediction) {
                if ($prediction->predicted_home_team_id === null
                    || $prediction->predicted_away_team_id === null
                    || $prediction->advancing_team_id !== null) {
                    continue;
                }

                $matchNumber = (int) $matchNumbers[$prediction->fixture_id];
                $pick = $this->knockoutPick(
                    $seedIndex,
                    $matchNumber,
                    (int) $prediction->predicted_home_team_id,
                    (int) $prediction->predicted_away_team_id,
                );

                $prediction->update([
                    'advancing_team_id' => $pick['advancingId'],
                    'home_goals' => $pick['homeGoals'],
                    'away_goals' => $pick['awayGoals'],
                ]);

                $progressed = true;
            }

            $resolver->persist($entry);

            if (! $progressed) {
                break;
            }
        }
    }

    /**
     * Fill a phased pool's knockout picks for one round, against the official participants the
     * projector has resolved so far. Unlike the upfront bracket there is no cascade: each round is
     * predicted directly against the real teams once they are known (the app-side analogue of the
     * phased save path, which snapshots the official teams onto the prediction). Fixtures the entry
     * has already predicted are left untouched.
     */
    private function generatePhasedKnockoutPredictions(Phase $phase, Pool $pool): void
    {
        $fixtures = $phase->fixtures()
            ->whereNotNull('home_team_id')
            ->whereNotNull('away_team_id')
            ->get();

        if ($fixtures->isEmpty()) {
            return;
        }

        foreach ($this->rosterByPool[$pool->id] ?? [] as [$entry, $seedIndex]) {
            if ($this->isMeSkipped($pool, $seedIndex)) {
                continue;
            }

            foreach ($fixtures as $fixture) {
                if ($entry->knockoutPredictions()->where('fixture_id', $fixture->id)->exists()) {
                    continue;
                }

                $matchNumber = (int) $fixture->match_number;
                $pick = $this->knockoutPick(
                    $seedIndex,
                    $matchNumber,
                    (int) $fixture->home_team_id,
                    (int) $fixture->away_team_id,
                );

                $entry->knockoutPredictions()->create([
                    'fixture_id' => $fixture->id,
                    'predicted_home_team_id' => $fixture->home_team_id,
                    'predicted_away_team_id' => $fixture->away_team_id,
                    'advancing_team_id' => $pick['advancingId'],
                    'home_goals' => $pick['homeGoals'],
                    'away_goals' => $pick['awayGoals'],
                ]);
            }
        }
    }

    /**
     * A deterministic knockout pick: who the player sends through, plus a scoreline that is a level
     * draw — decided on penalties — on roughly a third of matches, with the rest decisive. The
     * advancing pick is always set (the player still calls who goes through on a draw), so drawn-
     * knockout features get exercised: penalty advancement, the "advances" chip, drawn-score
     * scoring. Non-draw matches reuse the existing 'kwg'/'klg' noise so seeds stay reproducible.
     *
     * @return array{advancingId: int, homeGoals: int, awayGoals: int}
     */
    private function knockoutPick(int $seedIndex, int $matchNumber, int $homeTeamId, int $awayTeamId): array
    {
        $pickHome = $this->noise($seedIndex, $matchNumber, 'kp') < 0.5;
        $advancingId = $pickHome ? $homeTeamId : $awayTeamId;

        if ($this->noise($seedIndex, $matchNumber, 'kdraw') < 0.3) {
            // Level after regulation — the player still calls who advances on penalties.
            $level = (int) floor($this->noise($seedIndex, $matchNumber, 'kdg') * 3); // 0–2

            return ['advancingId' => $advancingId, 'homeGoals' => $level, 'awayGoals' => $level];
        }

        $winnerGoals = 1 + (int) floor($this->noise($seedIndex, $matchNumber, 'kwg') * 3);
        $loserGoals = (int) floor($this->noise($seedIndex, $matchNumber, 'klg') * $winnerGoals);

        return [
            'advancingId' => $advancingId,
            'homeGoals' => $pickHome ? $winnerGoals : $loserGoals,
            'awayGoals' => $pickHome ? $loserGoals : $winnerGoals,
        ];
    }

    /**
     * Clear any explicit predictions_lock_at override so the schedule-derived group-stage lock
     * ({@see Pool::predictionsLockAt()}) governs the window relative to the simulated clock: closed
     * once the clock is advanced past the first kickoff, open while simulating a pre-kickoff state.
     * Writing null every run also self-heals stale overrides left by older versions of this command.
     */
    private function clearPredictionsLockOverride(Tournament $tournament): void
    {
        $tournament->pools()->update(['predictions_lock_at' => null]);
    }

    /**
     * Play official results, re-projecting and re-scoring after each phase so the leaderboard
     * movement reflects each round. Bounded either by phase (--through) or, when $until is given,
     * by match end time — filling only matches finished by that datetime, which can land mid-phase.
     */
    private function playResults(Tournament $tournament, PhaseKey $through, ?CarbonImmutable $until, OfficialBracketProjector $projector, ScoreEngine $engine, RankSnapshotter $snapshotter, DefaultTieOrdering $defaultTieOrdering): void
    {
        $throughOrder = (int) $tournament->phases()->where('key', $through->value)->value('sort_order');

        foreach ($tournament->phases()->orderBy('sort_order')->get() as $phase) {
            // A date cutoff spans every phase; a phase cutoff stops at the requested phase.
            if ($until === null && $phase->sort_order > $throughOrder) {
                break;
            }

            if ($phase->key === PhaseKey::Group) {
                $this->fillGroupResults($tournament, $until);
                // No human to break ties in a simulation: apply the deterministic default order so
                // the official bracket projects fully.
                $defaultTieOrdering->applyToTournament($tournament);
            } else {
                // Phased pools predict this round now its official participants are known (projected
                // after the previous phase), before its results are filled and scored below.
                foreach ($tournament->pools as $pool) {
                    // Both phased and rolling pools predict each knockout round against its official
                    // participants once projected (rolling just locks per fixture rather than per round).
                    if ($pool->predictsOfficialBracket()) {
                        $this->generatePhasedKnockoutPredictions($phase, $pool);
                    }
                }

                $this->fillKnockoutResults($phase, $until);
            }

            // Official results are shared; re-project once, then re-score each pool over them.
            $projector->project($tournament);

            foreach ($tournament->pools as $pool) {
                $engine->recompute($pool);
                $snapshotter->snapshot($pool);
            }

            $this->components->task("Played {$phase->name}");
        }
    }

    private function fillGroupResults(Tournament $tournament, ?CarbonImmutable $until = null): void
    {
        foreach ($tournament->groups()->with(['teams', 'fixtures'])->get() as $group) {
            $positions = $group->teams->mapWithKeys(
                fn ($team): array => [$team->id => (int) $team->pivot->position],
            );

            foreach ($group->fixtures as $fixture) {
                if (! $this->endedBy($fixture, $until)) {
                    continue;
                }

                $home = $this->biasedGoals($this->noise($fixture->match_number, 'oh'), $positions[$fixture->home_team_id]);
                $away = $this->biasedGoals($this->noise($fixture->match_number, 'oa'), $positions[$fixture->away_team_id]);

                $fixture->update([
                    'home_goals' => $home,
                    'away_goals' => $away,
                    'winner_team_id' => $home === $away
                        ? null
                        : ($home > $away ? $fixture->home_team_id : $fixture->away_team_id),
                    'status' => FixtureStatus::Finished,
                ]);
            }
        }
    }

    private function fillKnockoutResults(Phase $phase, ?CarbonImmutable $until = null): void
    {
        $fixtures = $phase->fixtures()
            ->whereNotNull('home_team_id')
            ->whereNotNull('away_team_id')
            ->get();

        foreach ($fixtures as $fixture) {
            if (! $this->endedBy($fixture, $until)) {
                continue;
            }

            $matchNumber = $fixture->match_number;
            $homeAdvances = $this->noise($matchNumber, 'kw') < 0.5;
            $winnerId = $homeAdvances ? $fixture->home_team_id : $fixture->away_team_id;

            $attributes = [
                'winner_team_id' => $winnerId,
                'status' => FixtureStatus::Finished,
                'home_penalties' => null,
                'away_penalties' => null,
            ];

            if ($this->noise($matchNumber, 'pen') < 0.2) {
                // Level after regulation — decided on penalties.
                $level = (int) floor($this->noise($matchNumber, 'dg') * 3);
                $winnerPens = 4 + (int) floor($this->noise($matchNumber, 'wp') * 2);
                $loserPens = 2 + (int) floor($this->noise($matchNumber, 'lp') * 2);

                $attributes['home_goals'] = $level;
                $attributes['away_goals'] = $level;
                $attributes['home_penalties'] = $homeAdvances ? $winnerPens : $loserPens;
                $attributes['away_penalties'] = $homeAdvances ? $loserPens : $winnerPens;
            } else {
                $winnerGoals = 1 + (int) floor($this->noise($matchNumber, 'wg') * 3);
                $loserGoals = (int) floor($this->noise($matchNumber, 'lg') * $winnerGoals);

                $attributes['home_goals'] = $homeAdvances ? $winnerGoals : $loserGoals;
                $attributes['away_goals'] = $homeAdvances ? $loserGoals : $winnerGoals;
            }

            $fixture->update($attributes);
        }
    }

    /**
     * Whether a fixture's match has finished by the given cutoff (null cutoff = always).
     */
    private function endedBy(Fixture $fixture, ?CarbonImmutable $until): bool
    {
        if ($until === null) {
            return true;
        }

        return $fixture->kicks_off_at !== null
            && $until->gte($fixture->kicks_off_at->addMinutes((int) config('scoring.match_duration_minutes')));
    }

    /**
     * Move the simulated world to the cutoff date: bring matches that have kicked off but aren't
     * finished to live (so the "ended" gate opens as the clock reaches them), and set the local
     * dev clock so web requests and the scheduler share the same simulated "now".
     */
    private function advanceTo(Tournament $tournament, CarbonImmutable $until): void
    {
        $goLive = app(GoLive::class);

        // Drive each due fixture live through the same action the admin uses, so the simulated
        // world also gets live scoreboards to demo the Live Center (force bypasses the buffer gate).
        $tournament->fixtures()
            ->where('status', FixtureStatus::Scheduled)
            ->whereNotNull('kicks_off_at')
            ->where('kicks_off_at', '<=', $until)
            ->get()
            ->each(fn (Fixture $fixture) => $goLive->force($fixture));

        if ($this->getLaravel()->environment('local')) {
            DevClock::travelTo($until);
        }
    }

    /**
     * @param  Collection<int, Pool>  $pools
     */
    private function summarise(Collection $pools, PhaseKey $through, bool $predictOnly = false, ?CarbonImmutable $until = null): void
    {
        foreach ($pools as $pool) {
            $this->newLine();

            // Sibling pools share the "World Cup 2026" name, so lead with the source to tell them apart.
            if ($predictOnly) {
                $this->components->info("{$pool->source}: set up {$pool->entries()->count()} players with predictions — no results filled.");

                continue;
            }

            $this->components->info($until !== null
                ? "{$pool->source}: simulated up to {$until->toDayDateTimeString()} UTC."
                : "{$pool->source}: simulated through the {$through->value} phase.");

            $top = $pool->entries()
                ->with('user')
                ->orderByRaw('total_points IS NULL, total_points DESC, id')
                ->limit(5)
                ->get();

            $this->table(
                ['Rank', 'Player', 'Points', 'Move'],
                $top->map(fn (Entry $entry): array => [
                    $entry->rank ?? '—',
                    $entry->user->name ?? 'Player',
                    $entry->total_points ?? '—',
                    $this->movementArrow($entry),
                ])->all(),
            );
        }

        $me = (string) $this->option('me');
        $this->newLine();

        if ($predictOnly) {
            $this->components->info($until !== null
                ? "Clock moved to {$until->toDayDateTimeString()} UTC; matches finished by then are awaiting scores. Run `php artisan scores:fetch` (set SCORING_SIMULATED_PROVIDER=true) or use the review screen to enter them."
                : 'Play matches out by re-running with `--until` (advances the clock and runs matches live), e.g. `php artisan tournament:simulate --until="2026-06-13"` — or mark them live in the Live Center, then `php artisan scores:fetch` (set SCORING_SIMULATED_PROVIDER=true) or the review screen.');
        } else {
            $this->components->info('View it: run `composer dev`, then open a pool — settled cards + points, the standings table (rank arrows), and "Compare players" on the pool.');
        }

        if ($me !== '') {
            $this->components->info("Log in at /login as {$me} — the 6-digit code is written to the log (`php artisan pail`).");
        }

        if ($this->meSkipPoolId !== null && $me !== '') {
            $skip = $pools->firstWhere('id', $this->meSkipPoolId);

            if ($skip !== null) {
                $this->newLine();
                $this->components->info("Left {$me} with no predictions in {$skip->source} — open ".route('pools.predict.edit', $skip).' to see the "import from another pool" suggestion.');
            }
        }

        if (($playerTie = $this->option('player-tie')) !== null) {
            $this->reportPlayerTie($pools, $playerTie, $predictOnly);
        }
    }

    /**
     * Tell the operator what unresolved predicted tie was left and how to drive the player-side
     * tie-resolution flow. Warns when results were played, since that locks the prediction window
     * and makes the tie UI read-only.
     *
     * @param  Collection<int, Pool>  $pools
     */
    private function reportPlayerTie(Collection $pools, string $tie, bool $predictOnly): void
    {
        $upfront = $pools->first(fn (Pool $pool): bool => $pool->predictsKnockoutBracket());
        $me = (string) $this->option('me');

        $description = $tie === 'group'
            ? 'every group in their predictions is level — order who finishes 1st/2nd/3rd in each'
            : 'their best third-placed teams are tied across the qualifying cut — order which thirds classify';

        $this->newLine();
        $this->components->info("Left players with an UNRESOLVED predicted tie: {$description}. Demo players' natural ties are left unresolved too.");

        if (! $predictOnly) {
            $this->components->warn('Results were played, so the prediction window is likely locked and the tie UI read-only. Re-run with --predict-only to resolve it in the UI.');
        }

        if ($upfront !== null && $me !== '') {
            $this->components->info('Open '.route('pools.predict.edit', $upfront)." as {$me} to resolve the tie (the 6-digit login code is written to the log — `php artisan pail`).");
        }
    }

    private function movementArrow(Entry $entry): string
    {
        if ($entry->rank === null || $entry->previous_rank === null) {
            return $entry->rank === null ? '—' : 'new';
        }

        return match (true) {
            $entry->rank < $entry->previous_rank => '▲',
            $entry->rank > $entry->previous_rank => '▼',
            default => '–',
        };
    }

    private function goals(int|string ...$seedParts): int
    {
        return (int) floor($this->noise(...$seedParts) * 4); // 0–3
    }

    private function biasedGoals(float $noise, int $position): int
    {
        return $this->scores->biasedGoals($noise, $position);
    }

    private function noise(int|string ...$parts): float
    {
        return $this->scores->noise(...$parts);
    }
}
