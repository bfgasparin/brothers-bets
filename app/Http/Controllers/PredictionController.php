<?php

namespace App\Http\Controllers;

use App\Enums\OrderingScope;
use App\Enums\PredictionWindowStatus;
use App\Http\Controllers\Concerns\BuildsPoolIdentity;
use App\Http\Controllers\Concerns\PersistsTieOrdering;
use App\Http\Requests\Predictions\ImportPredictionsRequest;
use App\Http\Requests\Predictions\UpdateGroupOrderingRequest;
use App\Http\Requests\Predictions\UpdateGroupPredictionsRequest;
use App\Http\Requests\Predictions\UpdateKnockoutPredictionsRequest;
use App\Models\Entry;
use App\Models\Fixture;
use App\Models\Group;
use App\Models\GroupPrediction;
use App\Models\KnockoutPrediction;
use App\Models\Pool;
use App\Models\Team;
use App\Models\Tournament;
use App\Services\Pools\PredictionAttention;
use App\Services\Predictions\BracketResolver;
use App\Services\Predictions\GroupStandings;
use App\Services\Predictions\GroupStandingsPresenter;
use App\Services\Predictions\KnockoutSlotResolver;
use App\Services\Predictions\ManualTieOrdering;
use App\Services\Predictions\PredictionImporter;
use App\Services\Predictions\PredictionWindowResolver;
use App\Services\Predictions\ResolvedBracket;
use App\Services\Scoring\MatchdayCatalog;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Inertia\Inertia;
use Inertia\Response;

class PredictionController extends Controller
{
    use BuildsPoolIdentity;
    use PersistsTieOrdering;

    public function __construct(
        private readonly BracketResolver $resolver,
        private readonly PredictionWindowResolver $windowResolver,
        private readonly PredictionImporter $importer,
        private readonly PredictionAttention $attention,
    ) {}

    /**
     * Show the prediction wizard for a pool, prefilled with the user's saved picks
     * and the bracket teams resolved from their group-stage scores.
     */
    public function edit(Request $request, Pool $pool): Response|RedirectResponse
    {
        $windows = $this->windowResolver->windows($pool);
        $canEdit = $pool->acceptsPredictions();

        $entry = $pool->entries()->where('user_id', $request->user()->id)->first();

        if ($entry === null) {
            // Predictions require joining the pool first; send non-members to the pool page to join.
            return to_route('pools.show', $pool);
        }

        // An organizer-authored bracket (admin backfill of a JSON whose classification didn't follow
        // FIFA rules) must never be re-derived — and persist() below would do exactly that on load.
        // Block the wizard entirely; the player views the rest of the pool but can't edit these picks.
        if ($entry->hasAuthoredBracket()) {
            Inertia::flash('toast', [
                'type' => 'info',
                'message' => __('Your predictions were entered by an organizer and can’t be edited here.'),
            ]);

            return to_route('pools.show', $pool);
        }

        // Upfront pools cascade the self-derived bracket onto the entry's knockout rows; phased
        // pools predict the official bracket, so there is nothing to cascade.
        if ($pool->predictsKnockoutBracket()) {
            $this->resolver->persist($entry);
        }

        $tournament = $pool->tournament;
        $tournament->load([
            'groups.teams',
            'groups.fixtures' => fn ($query) => $query->orderBy('match_number'),
            'knockoutFixtures' => fn ($query) => $query->orderBy('match_number'),
            'knockoutFixtures.phase',
        ]);

        $bracket = $this->resolveBracket($tournament, $entry);
        $teamsById = $tournament->groups->flatMap->teams->keyBy('id');

        $groupPredictions = $entry?->groupPredictions->keyBy('fixture_id') ?? collect();
        $knockoutPredictions = $entry?->knockoutPredictions->keyBy('fixture_id') ?? collect();

        // The matchday each group fixture belongs to, so the wizard can mark every row like the pool
        // page does. Derived identically to the leaderboard's timeline (see MatchdayCatalog).
        $fixtureMatchdays = (new MatchdayCatalog)->fixtureIndex($tournament);

        return Inertia::render('pools/predict', [
            'pool' => [
                ...$this->poolIdentity($pool),
                'sport' => $tournament->sport->value,
                'status' => $tournament->status->value,
                'scoring_strategy' => $pool->scoring_strategy->value,
                'starts_on' => $tournament->starts_on?->toDateString(),
                'ends_on' => $tournament->ends_on?->toDateString(),
                'predictions_lock_at' => $pool->predictionsLockAt()?->toIso8601String(),
                'can_edit' => $canEdit,
                'scoring_config' => $pool->scoring_config,
            ],
            'groups' => $tournament->groups->map(
                fn (Group $group): array => $this->mapGroup($group, $bracket, $groupPredictions, $teamsById, $pool->predictsKnockoutBracket(), $fixtureMatchdays),
            )->all(),
            'bracket' => $pool->predictsKnockoutBracket()
                ? $this->mapBracket($tournament->knockoutFixtures, $bracket, $knockoutPredictions, $teamsById, $windows)
                : $this->mapOfficialBracket($tournament->knockoutFixtures, $knockoutPredictions, $teamsById, $windows),
            'thirds' => $pool->predictsKnockoutBracket() ? $this->mapThirds($bracket, $teamsById) : null,
            'thirds_tie' => $this->mapThirdsTie($pool, $bracket, $tournament, $teamsById, $entry !== null ? ManualTieOrdering::fromEntry($entry)->thirds : null),
            // Sibling pools the user can copy predictions from, and whether to nudge them to do so.
            'import_sources' => $this->importer->eligibleSources($pool, $request->user()),
            'should_suggest_import' => $this->importer->shouldSuggest($pool, $request->user()),
            // Phased pools can't resolve standings ties (the bracket is official); flag when one
            // exists so the wizard can explain instead of leaving the user looking for a control.
            'show_tie_note' => $this->phasedStandingsHaveTies($pool, $bracket, $tournament),
            // Whether every prediction in a currently-open window is in — drives the wizard's
            // "you're all set" celebration. Computed after the upfront cascade above so a wipe is
            // reflected before we decide the player is done.
            'completion' => $this->attention->completion($pool, $entry)->toArray(),
        ]);
    }

    /**
     * Save the user's group-stage scores, then recompute the resolved bracket.
     */
    public function updateGroupStage(UpdateGroupPredictionsRequest $request, Pool $pool): RedirectResponse
    {
        $entry = $request->entry();

        DB::transaction(function () use ($entry, $request): void {
            foreach ($request->validated('predictions') as $prediction) {
                GroupPrediction::updateOrCreate(
                    ['entry_id' => $entry->id, 'fixture_id' => $prediction['fixture_id']],
                    ['home_goals' => $prediction['home_goals'], 'away_goals' => $prediction['away_goals']],
                );
            }
        });

        // Only upfront pools derive the knockout bracket from group scores; phased pools leave the
        // knockout rounds to be predicted against the official match-ups.
        if ($pool->predictsKnockoutBracket()) {
            $this->resolver->persist($entry);
        }

        return to_route('pools.predict.edit', $pool);
    }

    /**
     * Save the user's knockout scores and advancing picks, then cascade the bracket.
     */
    public function updateKnockout(UpdateKnockoutPredictionsRequest $request, Pool $pool): RedirectResponse
    {
        $entry = $request->entry();

        if ($pool->predictsKnockoutBracket()) {
            // Upfront: write the scores/advancing onto the cascaded rows, then re-cascade so a
            // changed pick flows down the rest of the self-derived bracket.
            DB::transaction(function () use ($entry, $request): void {
                foreach ($request->predictionsForPersistence() as $prediction) {
                    KnockoutPrediction::updateOrCreate(
                        ['entry_id' => $entry->id, 'fixture_id' => $prediction['fixture_id']],
                        [
                            'home_goals' => $prediction['home_goals'],
                            'away_goals' => $prediction['away_goals'],
                            'advancing_team_id' => $prediction['advancing_team_id'],
                        ],
                    );
                }
            });

            $this->resolver->persist($entry);

            return to_route('pools.predict.edit', $pool);
        }

        // Phased: the player predicts the official match-up directly. Record it against the
        // official participants and do not cascade — the bracket comes from real results.
        $officialFixtures = $pool->tournament->knockoutFixtures()->get()->keyBy('id');

        DB::transaction(function () use ($entry, $request, $officialFixtures): void {
            foreach ($request->predictionsForPersistence() as $prediction) {
                $fixture = $officialFixtures->get($prediction['fixture_id']);

                KnockoutPrediction::updateOrCreate(
                    ['entry_id' => $entry->id, 'fixture_id' => $prediction['fixture_id']],
                    [
                        'home_goals' => $prediction['home_goals'],
                        'away_goals' => $prediction['away_goals'],
                        'advancing_team_id' => $prediction['advancing_team_id'],
                        'predicted_home_team_id' => $fixture?->home_team_id,
                        'predicted_away_team_id' => $fixture?->away_team_id,
                    ],
                );
            }
        });

        return to_route('pools.predict.edit', $pool);
    }

    /**
     * Save the player's manual ordering of an unresolved tie (a within-group cluster or the
     * thirds cut), then re-cascade so the newly-ordered teams fill their bracket slots.
     */
    public function updateOrdering(UpdateGroupOrderingRequest $request, Pool $pool): RedirectResponse
    {
        $entry = $request->entry();
        $scope = OrderingScope::from($request->string('scope')->value());
        $cluster = array_map('intval', $request->input('ordered_team_ids'));

        $groupId = $scope === OrderingScope::WithinGroup
            ? $pool->tournament->groups()->where('name', $request->input('group'))->value('id')
            : null;

        $this->persistTieOrdering(fn () => $entry->groupOrderings(), $scope, $groupId, $cluster);

        $this->resolver->persist($entry);

        return to_route('pools.predict.edit', $pool);
    }

    /**
     * Overwrite the user's predictions in this pool's currently-open window(s) with their own
     * picks from a sibling pool of the same tournament, then reload the prefilled wizard.
     */
    public function import(ImportPredictionsRequest $request, Pool $pool): RedirectResponse
    {
        $this->importer->import($request->entry(), $request->sourcePool());

        Inertia::flash('toast', [
            'type' => 'success',
            'message' => __('Predictions imported from :pool.', ['pool' => $request->sourcePool()->name]),
        ]);

        return to_route('pools.predict.edit', $pool);
    }

    /**
     * Resolve the bracket for the entry, or an empty read-only bracket when the user has
     * no entry (e.g. a locked pool they never entered).
     */
    private function resolveBracket(Tournament $tournament, ?Entry $entry): ResolvedBracket
    {
        if ($entry !== null) {
            return $this->resolver->resolve($entry);
        }

        $standings = [];
        foreach ($tournament->groups as $group) {
            $standings[$group->name] = new GroupStandings($group, []);
        }

        return new ResolvedBracket($standings, null, []);
    }

    /**
     * @param  Collection<int, GroupPrediction>  $predictions
     * @param  Collection<int, Team>  $teamsById
     * @param  array<int, array{key: string, label: string, short_label: string, kind: string}>  $fixtureMatchdays
     * @return array<string, mixed>
     */
    private function mapGroup(Group $group, ResolvedBracket $bracket, Collection $predictions, Collection $teamsById, bool $surfaceTies, array $fixtureMatchdays): array
    {
        $standings = $bracket->standings[$group->name];

        // Phased pools can't reorder a standings tie (their bracket is official) and show no resolve
        // panel, so flag the genuinely-tied rows — otherwise the deterministic fallback order reads
        // like a bug. Upfront pools surface the editable tied_clusters panel instead.
        $tiedTeamIds = (! $surfaceTies && $standings->isComplete())
            ? collect($standings->tieClustersWithStatus())->flatMap(fn (array $cluster): array => $cluster['teamIds'])->all()
            : [];

        $rows = array_map(fn (array $row): array => [
            ...$row,
            'tied' => in_array($row['team']['id'] ?? null, $tiedTeamIds, true),
        ], GroupStandingsPresenter::rows($standings, $teamsById));

        return [
            'name' => $group->name,
            'teams' => $group->teams->map(fn (Team $team): array => [
                ...$this->teamRef($team),
                'position' => $team->pivot->position,
            ])->all(),
            'fixtures' => $group->fixtures->map(function (Fixture $fixture) use ($predictions, $teamsById, $fixtureMatchdays): array {
                $prediction = $predictions->get($fixture->id);

                return [
                    'fixture_id' => $fixture->id,
                    'match_number' => $fixture->match_number,
                    'matchday_key' => $fixtureMatchdays[$fixture->id]['key'] ?? null,
                    'home' => $this->teamRef($teamsById->get($fixture->home_team_id)),
                    'away' => $this->teamRef($teamsById->get($fixture->away_team_id)),
                    'home_goals' => $prediction?->home_goals,
                    'away_goals' => $prediction?->away_goals,
                    'kicks_off_at' => $fixture->kicks_off_at?->toIso8601String(),
                    'venue' => $fixture->venue,
                    'venue_timezone' => $fixture->venue_timezone,
                ];
            })->all(),
            'standings' => $rows,
            // Tied clusters the player drags into order (only for self-derived, complete groups), in
            // their effective order, each flagged resolved so the UI can confirm a saved choice.
            'tied_clusters' => $surfaceTies && $standings->isComplete()
                ? array_map(fn (array $cluster): array => [
                    'team_ids' => array_values($cluster['teamIds']),
                    'resolved' => $cluster['resolved'],
                ], $standings->tieClustersWithStatus())
                : [],
        ];
    }

    /**
     * The third-placed teams whose tie straddles the qualifying cut and so must be ordered before
     * the player's bracket can fill the best-third slots, in effective order plus whether an
     * ordering already resolves the cut, or null when there is no such tie.
     *
     * @param  Collection<int, Team>  $teamsById
     * @param  list<int>|null  $thirdsOrder
     * @return array{teams: list<array<string, mixed>>, resolved: bool}|null
     */
    private function mapThirdsTie(Pool $pool, ResolvedBracket $bracket, Tournament $tournament, Collection $teamsById, ?array $thirdsOrder): ?array
    {
        if (! $pool->predictsKnockoutBracket()) {
            return null;
        }

        $straddling = (new KnockoutSlotResolver)->straddlingThirds($bracket->standings, $tournament->groups, $thirdsOrder);

        if ($straddling === []) {
            return null;
        }

        return [
            'teams' => array_map(fn (int $teamId): ?array => $this->teamRef($teamsById->get($teamId)), $straddling),
            'resolved' => $bracket->rankedThirds !== null,
        ];
    }

    /**
     * Whether a phased pool's group standings contain a tie the player would expect to resolve (a
     * within-group cluster or a straddling best-thirds cut). Upfront pools surface the editable tie
     * panels instead; phased pools predict the official bracket, so a tie has no effect — the note
     * keyed off this just explains that, rather than leaving the player hunting for a control.
     */
    private function phasedStandingsHaveTies(Pool $pool, ResolvedBracket $bracket, Tournament $tournament): bool
    {
        if ($pool->predictsKnockoutBracket()) {
            return false;
        }

        foreach ($bracket->standings as $standings) {
            if ($standings->isComplete() && $standings->tieClustersWithStatus() !== []) {
                return true;
            }
        }

        return (new KnockoutSlotResolver)->straddlingThirds($bracket->standings, $tournament->groups, null) !== [];
    }

    /**
     * Group the upfront knockout fixtures into bracket columns ordered by phase progression, with
     * each slot's teams taken from the player's self-derived bracket.
     *
     * @param  Collection<int, Fixture>  $fixtures
     * @param  Collection<int, KnockoutPrediction>  $predictions
     * @param  Collection<int, Team>  $teamsById
     * @param  array<string, PredictionWindowStatus>  $windows
     * @return list<array<string, mixed>>
     */
    private function mapBracket(Collection $fixtures, ResolvedBracket $bracket, Collection $predictions, Collection $teamsById, array $windows): array
    {
        return $fixtures
            ->groupBy(fn (Fixture $fixture): string => $fixture->phase->key->value)
            ->map(fn (Collection $phaseFixtures): array => [
                'phase_key' => $phaseFixtures->first()->phase->key->value,
                'phase_name' => $phaseFixtures->first()->phase->name,
                'sort_order' => $phaseFixtures->first()->phase->sort_order,
                'window' => ($windows[$phaseFixtures->first()->phase->key->value] ?? PredictionWindowStatus::Locked)->value,
                'fixtures' => $phaseFixtures->map(function (Fixture $fixture) use ($bracket, $predictions, $teamsById): array {
                    $slot = $bracket->fixture($fixture->id);
                    $prediction = $predictions->get($fixture->id);

                    $home = $slot['home'] !== null ? $teamsById->get($slot['home']) : null;
                    $away = $slot['away'] !== null ? $teamsById->get($slot['away']) : null;

                    return [
                        'fixture_id' => $fixture->id,
                        'match_number' => $fixture->match_number,
                        'bracket_slot' => $fixture->bracket_slot,
                        'phase_key' => $fixture->phase->key->value,
                        'home' => $this->teamRef($home),
                        'away' => $this->teamRef($away),
                        'home_label' => $home?->name ?? $fixture->home_placeholder_label,
                        'away_label' => $away?->name ?? $fixture->away_placeholder_label,
                        'home_goals' => $prediction?->home_goals,
                        'away_goals' => $prediction?->away_goals,
                        'advancing_team_id' => $prediction?->advancing_team_id,
                    ];
                })->values()->all(),
            ])
            ->sortBy('sort_order')
            ->values()
            ->all();
    }

    /**
     * Group the phased knockout fixtures into bracket columns, with each slot's teams taken from
     * the official participants on the fixture (filled by the {@see OfficialBracketProjector} as
     * rounds complete). Each phase carries its prediction {@see PredictionWindowStatus} so the
     * wizard can open, lock, or hold each round.
     *
     * @param  Collection<int, Fixture>  $fixtures
     * @param  Collection<int, KnockoutPrediction>  $predictions
     * @param  Collection<int, Team>  $teamsById
     * @param  array<string, PredictionWindowStatus>  $windows
     * @return list<array<string, mixed>>
     */
    private function mapOfficialBracket(Collection $fixtures, Collection $predictions, Collection $teamsById, array $windows): array
    {
        return $fixtures
            ->groupBy(fn (Fixture $fixture): string => $fixture->phase->key->value)
            ->map(fn (Collection $phaseFixtures): array => [
                'phase_key' => $phaseFixtures->first()->phase->key->value,
                'phase_name' => $phaseFixtures->first()->phase->name,
                'sort_order' => $phaseFixtures->first()->phase->sort_order,
                'window' => ($windows[$phaseFixtures->first()->phase->key->value] ?? PredictionWindowStatus::Pending)->value,
                'fixtures' => $phaseFixtures->map(function (Fixture $fixture) use ($predictions, $teamsById): array {
                    $prediction = $predictions->get($fixture->id);

                    $home = $fixture->home_team_id !== null ? $teamsById->get($fixture->home_team_id) : null;
                    $away = $fixture->away_team_id !== null ? $teamsById->get($fixture->away_team_id) : null;

                    return [
                        'fixture_id' => $fixture->id,
                        'match_number' => $fixture->match_number,
                        'bracket_slot' => $fixture->bracket_slot,
                        'phase_key' => $fixture->phase->key->value,
                        'home' => $this->teamRef($home),
                        'away' => $this->teamRef($away),
                        'home_label' => $home?->name ?? $fixture->home_placeholder_label,
                        'away_label' => $away?->name ?? $fixture->away_placeholder_label,
                        'home_goals' => $prediction?->home_goals,
                        'away_goals' => $prediction?->away_goals,
                        'advancing_team_id' => $prediction?->advancing_team_id,
                    ];
                })->values()->all(),
            ])
            ->sortBy('sort_order')
            ->values()
            ->all();
    }

    /**
     * @param  Collection<int, Team>  $teamsById
     * @return list<array<string, mixed>>|null
     */
    private function mapThirds(ResolvedBracket $bracket, Collection $teamsById): ?array
    {
        if ($bracket->rankedThirds === null) {
            return null;
        }

        return collect($bracket->rankedThirds)
            ->map(fn (int $teamId, int $index): array => [
                'rank' => $index + 1,
                'team' => $this->teamRef($teamsById->get($teamId)),
            ])
            ->all();
    }

    /**
     * @return array{id: int, name: string, code: ?string, is_placeholder: bool, flag_url: string}|null
     */
    private function teamRef(?Team $team): ?array
    {
        if ($team === null) {
            return null;
        }

        return [
            'id' => $team->id,
            'name' => $team->name,
            'code' => $team->code,
            'is_placeholder' => $team->is_placeholder,
            'flag_url' => $team->flag_url,
        ];
    }
}
