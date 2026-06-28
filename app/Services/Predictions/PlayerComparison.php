<?php

namespace App\Services\Predictions;

use App\Enums\LeaderboardCategory;
use App\Enums\PhaseKey;
use App\Enums\PredictionWindowStatus;
use App\Models\Entry;
use App\Models\Group;
use App\Models\GroupPrediction;
use App\Models\KnockoutPrediction;
use App\Models\LeaderboardStanding;
use App\Models\Pool;
use App\Models\Team;
use App\Models\Tournament;
use Illuminate\Support\Collection;

/**
 * Builds the head-to-head "compare players" payload for the pool detail page: the viewer's own
 * results alongside up to a few selected opponents, so the page can render one lane per player.
 *
 * The load-bearing rule is the anti-cheat gate: another player's actual predictions (scorelines,
 * advancing picks, the projected table derived from them) are only ever exposed for a fixture whose
 * prediction window has {@see PredictionWindowStatus::Locked}. The viewer's own picks always show,
 * and points/standings (which exist only after results, i.e. always post-lock) are never gated.
 * A hidden prediction is omitted entirely from the payload — never serialised then blanked — so the
 * wire never carries a pick the viewer should not see. The frontend tells "hidden by lock" (window
 * not locked) from "no prediction" (window locked, key absent) using the {@see $windows} map.
 */
class PlayerComparison
{
    public function __construct(
        private readonly PredictionWindowResolver $windowResolver = new PredictionWindowResolver,
    ) {}

    /**
     * The comparison payload for the viewer plus the selected opponent entries, or null when no
     * opponents are selected. The viewer is always lane 0; opponents follow in the given order.
     *
     * @param  list<int>  $entryIds  the opponent entry ids (already sanitised/capped), in display order
     * @return array{windows: array<string, string>, players: list<array<string, mixed>>}|null
     */
    public function build(Pool $pool, int $viewerUserId, array $entryIds): ?array
    {
        if ($entryIds === []) {
            return null;
        }

        $windows = $this->windowResolver->windows($pool);

        $tournament = $pool->tournament;
        $tournament->loadMissing([
            'groups.teams',
            'groups.fixtures',
            'knockoutFixtures.phase',
        ]);

        $phaseKeyByFixture = $this->phaseKeyByFixture($tournament);
        $fixtureStatuses = $this->fixtureStatuses($pool, $tournament, $phaseKeyByFixture, $windows);

        $viewerEntry = $pool->entries()
            ->where('user_id', $viewerUserId)
            ->with($this->entryLoads())
            ->first();

        $opponents = $pool->entries()
            ->whereIn('id', $entryIds)
            ->with($this->entryLoads())
            ->get()
            // Preserve the requested order, not the database order.
            ->sortBy(fn (Entry $entry): int => array_search($entry->id, $entryIds, true))
            ->values();

        $players = [$this->player($viewerEntry, $tournament->groups, $phaseKeyByFixture, $windows, $fixtureStatuses, true, $pool)];

        foreach ($opponents as $opponent) {
            $players[] = $this->player($opponent, $tournament->groups, $phaseKeyByFixture, $windows, $fixtureStatuses, false, $pool);
        }

        return [
            'windows' => array_map(fn (PredictionWindowStatus $status): string => $status->value, $windows),
            'players' => $players,
        ];
    }

    /**
     * One player's lane. Predictions are gated per fixture; totals/rank/per-board values always show.
     *
     * @param  Collection<int, Group>  $groups
     * @param  array<int, string>  $phaseKeyByFixture  fixture id => phase key value
     * @param  array<string, PredictionWindowStatus>  $windows
     * @return array<string, mixed>
     */
    private function player(?Entry $entry, Collection $groups, array $phaseKeyByFixture, array $windows, array $fixtureStatuses, bool $isViewer, Pool $pool): array
    {
        $groupPredictions = $entry?->groupPredictions->keyBy('fixture_id') ?? collect();
        $knockoutPredictions = $entry?->knockoutPredictions->keyBy('fixture_id') ?? collect();

        return [
            'entry_id' => $entry?->id,
            'user_id' => $entry?->user_id,
            'name' => $isViewer ? __('You') : ($entry?->user->name ?? __('Player')),
            'initials' => $this->initials($entry?->user->name ?? ''),
            'avatar' => $entry?->user->avatar,
            'is_viewer' => $isViewer,
            'total_points' => $entry?->total_points,
            'rank' => $entry?->rank,
            'boards' => $this->boards($entry),
            'group_predictions' => $this->groupPredictionMap($groupPredictions, $fixtureStatuses, $isViewer),
            'knockout_predictions' => $this->knockoutPredictionMap($knockoutPredictions, $fixtureStatuses, $isViewer, $pool),
            'projected_standings' => $this->projectedStandings($groups, $groupPredictions, $windows, $isViewer, $pool->predictsKnockoutBracket()),
        ];
    }

    /**
     * Each board's value for the player: Overall reads the entry's total points (mirroring the
     * snapshot), every other board reads its {@see LeaderboardStanding} value. Always exposed.
     *
     * @return list<array{key: string, primary_value: ?int}>
     */
    private function boards(?Entry $entry): array
    {
        $standings = $entry?->standings->keyBy(fn (LeaderboardStanding $standing): string => $standing->category->value)
            ?? collect();

        return array_map(fn (LeaderboardCategory $category): array => [
            'key' => $category->value,
            'primary_value' => $category === LeaderboardCategory::Overall
                ? $entry?->total_points
                : $standings->get($category->value)?->value,
        ], LeaderboardCategory::ordered());
    }

    /**
     * The player's group-stage predictions keyed by fixture id, gated by the fixture's window.
     *
     * @param  Collection<int, GroupPrediction>  $predictions  keyed by fixture id
     * @param  array<int, PredictionWindowStatus>  $fixtureStatuses  fixture id => window status
     * @return array<int, array{home_goals: int, away_goals: int, points_awarded: ?int}>
     */
    private function groupPredictionMap(Collection $predictions, array $fixtureStatuses, bool $isViewer): array
    {
        $map = [];

        foreach ($predictions as $fixtureId => $prediction) {
            if ($prediction->home_goals === null || $prediction->away_goals === null) {
                continue;
            }

            if (! $this->revealedFixture($isViewer, $fixtureStatuses[$fixtureId] ?? null)) {
                continue;
            }

            $map[$fixtureId] = [
                'home_goals' => $prediction->home_goals,
                'away_goals' => $prediction->away_goals,
                'points_awarded' => $prediction->points_awarded,
            ];
        }

        return $map;
    }

    /**
     * The player's knockout picks keyed by fixture id, gated by the fixture's window. Predicted
     * teams are exposed only for upfront-bracket pools (where they differ from the official ones).
     *
     * @param  Collection<int, KnockoutPrediction>  $predictions  keyed by fixture id
     * @param  array<int, PredictionWindowStatus>  $fixtureStatuses  fixture id => window status
     * @return array<int, array<string, mixed>>
     */
    private function knockoutPredictionMap(Collection $predictions, array $fixtureStatuses, bool $isViewer, Pool $pool): array
    {
        $showTeams = $pool->predictsKnockoutBracket();
        $map = [];

        foreach ($predictions as $fixtureId => $prediction) {
            if (! $this->revealedFixture($isViewer, $fixtureStatuses[$fixtureId] ?? null)) {
                continue;
            }

            // Skip rows the player never engaged with (no teams reached, no score, no pick).
            $hasContent = $prediction->predicted_home_team_id !== null
                || $prediction->advancing_team_id !== null
                || $prediction->home_goals !== null
                || $prediction->away_goals !== null;

            if (! $hasContent) {
                continue;
            }

            $map[$fixtureId] = [
                'home_goals' => $prediction->home_goals,
                'away_goals' => $prediction->away_goals,
                'advancing_team_id' => $prediction->advancing_team_id,
                'points_awarded' => $prediction->points_awarded,
                'predicted_home' => $showTeams ? $this->teamRef($prediction->predictedHomeTeam) : null,
                'predicted_away' => $showTeams ? $this->teamRef($prediction->predictedAwayTeam) : null,
            ];
        }

        return $map;
    }

    /**
     * The player's projected group tables from their own predicted scores, keyed by group name.
     * A group's table reveals that player's picks, so it is null unless the group-stage window has
     * locked (or this is the viewer); also null when they have not predicted that group.
     *
     * @param  Collection<int, Group>  $groups
     * @param  Collection<int, GroupPrediction>  $groupPredictions  keyed by fixture id
     * @param  array<string, PredictionWindowStatus>  $windows
     * @param  bool  $showProjected  whether projected tables are meaningful (upfront pools only)
     * @return array<string, list<array<string, mixed>>|null>
     */
    private function projectedStandings(Collection $groups, Collection $groupPredictions, array $windows, bool $isViewer, bool $showProjected): array
    {
        // Phased pools predict the official bracket, so a projected group order decides nothing —
        // never surface it for comparison (the frontend then drops the standings toggle).
        $revealed = $showProjected && $this->revealed($isViewer, PhaseKey::Group->value, $windows);
        $map = [];

        foreach ($groups as $group) {
            if (! $revealed) {
                $map[$group->name] = null;

                continue;
            }

            $predictions = [];
            foreach ($group->fixtures as $fixture) {
                if ($groupPredictions->has($fixture->id)) {
                    $predictions[$fixture->id] = $groupPredictions->get($fixture->id);
                }
            }

            $map[$group->name] = $predictions === []
                ? null
                : GroupStandingsPresenter::rows(new GroupStandings($group, $predictions), $group->teams->keyBy('id'));
        }

        return $map;
    }

    /**
     * Whether a player's prediction for a fixture in the given phase may be shown: always for the
     * viewer's own lane, otherwise only once that phase's prediction window has locked.
     *
     * @param  array<string, PredictionWindowStatus>  $windows
     */
    private function revealed(bool $isViewer, ?string $phaseKey, array $windows): bool
    {
        if ($isViewer) {
            return true;
        }

        if ($phaseKey === null) {
            return false;
        }

        return ($windows[$phaseKey] ?? null) === PredictionWindowStatus::Locked;
    }

    /**
     * Whether a player's prediction for a fixture may be shown, judged by that fixture's own window
     * status: always for the viewer, otherwise only once the fixture has {@see PredictionWindowStatus::Locked}.
     * This is the per-fixture reveal that lets a rolling pool expose a kicked-off match while a
     * still-open sibling stays hidden.
     */
    private function revealedFixture(bool $isViewer, ?PredictionWindowStatus $status): bool
    {
        return $isViewer || $status === PredictionWindowStatus::Locked;
    }

    /**
     * The prediction window status of every fixture, keyed by fixture id. A per-match (rolling) pool
     * resolves each fixture on its own kickoff; every other strategy inherits its phase's window, so
     * each fixture maps to its phase status — preserving the existing per-phase reveal behaviour.
     *
     * @param  array<int, string>  $phaseKeyByFixture
     * @param  array<string, PredictionWindowStatus>  $windows
     * @return array<int, PredictionWindowStatus>
     */
    private function fixtureStatuses(Pool $pool, Tournament $tournament, array $phaseKeyByFixture, array $windows): array
    {
        if (! $pool->usesPerMatchPredictionWindows()) {
            $map = [];
            foreach ($phaseKeyByFixture as $fixtureId => $phaseKey) {
                $map[$fixtureId] = $windows[$phaseKey] ?? PredictionWindowStatus::Pending;
            }

            return $map;
        }

        $map = [];

        foreach ($tournament->groups as $group) {
            foreach ($group->fixtures as $fixture) {
                $map[$fixture->id] = $this->windowResolver->statusForFixture($pool, $fixture);
            }
        }

        foreach ($tournament->knockoutFixtures as $fixture) {
            $map[$fixture->id] = $this->windowResolver->statusForFixture($pool, $fixture);
        }

        return $map;
    }

    /**
     * A fixture id => phase key lookup for every fixture (group and knockout), so each prediction
     * can find its prediction window. Group fixtures all sit in the single group-stage phase.
     *
     * @return array<int, string>
     */
    private function phaseKeyByFixture(Tournament $tournament): array
    {
        $map = [];

        foreach ($tournament->groups as $group) {
            foreach ($group->fixtures as $fixture) {
                $map[$fixture->id] = PhaseKey::Group->value;
            }
        }

        foreach ($tournament->knockoutFixtures as $fixture) {
            $map[$fixture->id] = $fixture->phase->key->value;
        }

        return $map;
    }

    /**
     * @return list<string>
     */
    private function entryLoads(): array
    {
        return [
            'user:id,name,avatar_path',
            'standings',
            'groupPredictions',
            'knockoutPredictions.predictedHomeTeam',
            'knockoutPredictions.predictedAwayTeam',
        ];
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

    /**
     * Up to two initials from a display name (e.g. "Marina Jones" -> "MJ").
     */
    private function initials(string $name): string
    {
        $parts = array_values(array_filter(preg_split('/\s+/', trim($name)) ?: []));

        $letters = collect($parts)
            ->take(2)
            ->map(fn (string $part): string => mb_substr($part, 0, 1))
            ->implode('');

        return mb_strtoupper($letters) ?: '?';
    }
}
