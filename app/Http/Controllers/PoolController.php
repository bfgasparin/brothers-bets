<?php

namespace App\Http\Controllers;

use App\Enums\LeaderboardCategory;
use App\Http\Controllers\Concerns\BuildsPoolIdentity;
use App\Http\Requests\Pools\JoinPoolRequest;
use App\Models\Entry;
use App\Models\Fixture;
use App\Models\Group;
use App\Models\GroupPrediction;
use App\Models\KnockoutPrediction;
use App\Models\LeaderboardStanding;
use App\Models\Pool;
use App\Models\Team;
use App\Models\Tournament;
use App\Models\User;
use App\Notifications\PlayerJoinedPoolNotification;
use App\Services\Pools\PredictionAttention;
use App\Services\Pools\PrizePot;
use App\Services\Predictions\GroupStandings;
use App\Services\Predictions\GroupStandingsPresenter;
use App\Services\Predictions\PlayerComparison;
use App\Services\Scoring\MatchdayCatalog;
use App\Services\Scoring\MatchdayLeaderboard;
use App\Services\Scoring\RankMovement;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Notification;
use Inertia\Inertia;
use Inertia\Response;

class PoolController extends Controller
{
    use BuildsPoolIdentity;

    /**
     * Rows shown for the headline (Overall) board on the pool page — a short top-N summary, since the
     * full standings live on the leaderboard page. The two side boards render as condensed summary
     * cards (leader + the viewer's place), not tables, stacked beside this table.
     */
    private const HEADLINE_BOARD_ROWS = 3;

    /**
     * Rows kept for each non-headline board on the pool page. Those boards render as summary cards
     * (leader + the viewer's pinned row), so only the leader ships here; the viewer arrives via the
     * pinned `me` row when ranked outside it.
     */
    private const SECONDARY_BOARD_ROWS = 1;

    /**
     * List the available pools (tournaments).
     *
     * Opening the installed PWA loads the manifest's start_url (`/pools?source=pwa`); on that one
     * marker we skip the picker and open the pool the player most recently joined, so relaunching
     * the app lands on a pool. Browser visits and the post-login redirect reach `/pools` without
     * the marker, so they are deliberately unaffected.
     */
    public function index(Request $request): RedirectResponse|Response
    {
        $userId = $request->user()->id;

        if ($request->query('source') === 'pwa') {
            $entry = Entry::query()
                ->where('user_id', $userId)
                ->with('pool')
                // The pool the player most recently joined; the id breaks ties between entries
                // created in the same second so the choice is deterministic.
                ->orderByDesc('created_at')
                ->orderByDesc('id')
                ->first();

            if ($entry !== null) {
                return redirect()->route('pools.show', $entry->pool);
            }
        }

        $pools = Pool::query()
            // The size of each pool (its entry count) — distinct per pool (sibling pools each have
            // their own entries), so it helps a player choose which to enter.
            ->withCount('entries')
            // Whether the viewer has already joined each pool, so the card can flag it and never
            // read "join" as the navigate-to-pool action.
            ->withExists(['entries as joined' => fn ($query) => $query->where('user_id', $userId)])
            ->with(['tournament' => fn ($query) => $query
                ->withCount(['groups', 'fixtures'])
                // The tournament's sibling pool ids, so each list item can carry its position among
                // them — used by the frontend to give same-tournament pools distinct kit colors.
                ->with('pools:id,tournament_id'),
            ])
            // Newest competition first; pools over the same tournament keep a stable order by id.
            ->orderByDesc(
                Tournament::select('starts_on')->whereColumn('tournaments.id', 'pools.tournament_id'),
            )
            ->orderBy('id')
            ->paginate(12)
            ->through(function (Pool $pool): array {
                $siblingIds = $pool->tournament->pools->sortBy('id')->pluck('id')->values();

                return [
                    // poolHeader() already carries scoring_label (via the pool-identity payload).
                    ...$this->poolHeader($pool),
                    // The one-line explainer of how this pool scores — what sets it apart from its
                    // siblings over the same tournament.
                    'scoring_description' => $pool->scoring_strategy->description(),
                    'groups_count' => $pool->tournament->groups_count,
                    'fixtures_count' => $pool->tournament->fixtures_count,
                    'tournament' => [
                        'id' => $pool->tournament->id,
                        'name' => $pool->tournament->name,
                    ],
                    // 0-based position among the tournament's pools (by id), stable across pages, so
                    // a pool's accent colour never shifts when the list paginates.
                    'accent_index' => $siblingIds->search($pool->id),
                    'players_count' => $pool->entries_count,
                    // Whether the viewer is already in this pool.
                    'joined' => (bool) $pool->joined,
                    // Whether joining is still open — the card shows the buy-in and percentage prize
                    // split while joinable, then switches to the now-final raw prize amounts.
                    'can_join' => $pool->acceptsJoins(),
                    // The buy-in and the prizes computed from the current pool size, so a player can
                    // weigh the stakes before opening the pool.
                    'pricing' => PrizePot::forPool($pool, $pool->entries_count)->toArray(),
                ];
            });

        return Inertia::render('pools/index', ['pools' => $pools]);
    }

    /**
     * Show a single pool's structure: groups, fixtures and the knockout bracket, plus the
     * viewer's pool standing for the dashboard banner.
     */
    public function show(Request $request, Pool $pool, PredictionAttention $attention): Response
    {
        $tournament = $pool->tournament;

        $tournament->load([
            'groups.teams',
            'groups.fixtures' => fn ($query) => $query->orderBy('match_number'),
            'groups.fixtures.homeTeam',
            'groups.fixtures.awayTeam',
            'groups.fixtures.liveState',
            'knockoutFixtures' => fn ($query) => $query->orderBy('match_number'),
            'knockoutFixtures.phase',
            'knockoutFixtures.homeTeam',
            'knockoutFixtures.awayTeam',
            'knockoutFixtures.winner',
            'knockoutFixtures.liveState',
        ]);

        // The viewer's own picks, so each fixture can show its predicted scoreline alongside the
        // official result and the points it earned. Knockout picks carry their predicted teams so
        // an upfront-bracket pool can show the match-up the player called.
        $entry = $pool->entries()
            ->where('user_id', $request->user()->id)
            ->with([
                'groupPredictions',
                'knockoutPredictions.predictedHomeTeam',
                'knockoutPredictions.predictedAwayTeam',
            ])
            ->first();
        $groupPredictions = $entry?->groupPredictions->keyBy('fixture_id') ?? collect();
        $knockoutPredictions = $entry?->knockoutPredictions->keyBy('fixture_id') ?? collect();

        // The matchday each fixture belongs to (group rounds 1-3, then one per knockout phase),
        // derived once and identical to the leaderboard's timeline, so every match can be marked
        // with — and grouped by — its matchday.
        $catalog = new MatchdayCatalog;
        $fixtureMatchdays = $catalog->fixtureIndex($tournament);

        // The "compare players" feature: when the page carries a ?compare= list of other entries,
        // resolve their results into a comparison payload alongside the viewer's. `players` is the
        // always-present directory the picker filters; `comparison` is null in normal mode.
        $compareIds = $this->parseCompareIds($request, $pool, $request->user()->id);

        // Every board, built once: the banner scalars, the featured full-table boards, and any
        // overflow boards (beyond the first three) all derive from this single load.
        $boards = $this->boards($pool, $request->user()->id);
        $standings = $this->poolScalars($boards);

        return Inertia::render('pools/show', [
            'pool' => [
                // poolHeader() already carries scoring_label (via the pool-identity payload).
                ...$this->poolHeader($pool),
                'scoring_strategy' => $pool->scoring_strategy->value,
                'scoring_description' => $pool->scoring_strategy->description(),
                'how_to_play' => $pool->scoring_strategy->howToPlay(),
                'scoring_config' => $pool->scoring_config,
                'predictions_lock_at' => $pool->predictionsLockAt()?->toIso8601String(),
                // Whether the player may still join (and pay in): the join window closes with the
                // group-stage prediction lock, mirroring can_edit on the predict screen.
                'can_join' => $pool->acceptsPredictions(),
                // Whether this viewer has already seen the "how it works" briefing, so the dialog
                // only auto-opens on their first visit to this pool.
                'has_seen_briefing' => $pool->briefingSeenBy($request->user()),
                'pricing' => PrizePot::forPool($pool, $standings['participants'])->toArray(),
                'leaderboards' => $this->boardDescriptors(),
            ],
            'groups' => $tournament->groups->map(
                fn (Group $group): array => $this->mapGroup($group, $groupPredictions, $pool->predictsKnockoutBracket(), $fixtureMatchdays),
            ),
            'bracket' => $this->mapBracket($tournament->knockoutFixtures, $knockoutPredictions, $pool->predictsKnockoutBracket(), $fixtureMatchdays),
            // The ordered matchday timeline the pool page's Matchdays/Schedule views group fixtures by.
            'matchdays' => $catalog->descriptors($tournament),
            'standings' => $standings,
            // The first three boards as full tables; any beyond that stay as condensed summaries.
            'featuredBoards' => $this->featuredBoards($boards),
            'moreBoards' => $this->moreBoards($boards),
            'players' => $this->playerDirectory($pool, $request->user()->id),
            'comparison' => (new PlayerComparison)->build($pool, $request->user()->id, $compareIds),
            // What prediction work the viewer still has in an open window, for the reminder banner.
            'attention' => $attention->summary($pool, $entry)->toArray(),
        ]);
    }

    /**
     * Join the pool. Creates the player's entry (the act of joining), which is the
     * prerequisite for making predictions. Payment is arranged externally with the organizer, so
     * this only records participation. Idempotent — backed by the unique (pool_id, user_id) index.
     */
    public function join(JoinPoolRequest $request, Pool $pool): RedirectResponse
    {
        $entry = $pool->entries()->firstOrCreate(['user_id' => $request->user()->id]);

        // Only on a genuinely new join (firstOrCreate is idempotent): tell the organizers so they
        // can arrange the externally-collected buy-in with the player.
        if ($entry->wasRecentlyCreated) {
            Notification::send(User::admins()->get(), new PlayerJoinedPoolNotification($pool, $request->user()));
        }

        return to_route('pools.show', $pool);
    }

    /**
     * Record that the viewer has seen this pool's "how it works" briefing, so it doesn't auto-open
     * on their next visit. A fire-and-forget side effect from the dialog — no body, no redirect.
     * Idempotent via {@see Pool::markBriefingSeenBy()}.
     */
    public function markBriefingSeen(Request $request, Pool $pool): \Illuminate\Http\Response
    {
        $pool->markBriefingSeenBy($request->user());

        return response()->noContent();
    }

    /**
     * The opponent entry ids to compare, parsed from the `?compare=` CSV. Sanitised to at most
     * three distinct other entries that belong to *this* pool (never the viewer's own), in the
     * order requested; foreign, duplicate or garbage ids are silently dropped.
     *
     * @return list<int>
     */
    private function parseCompareIds(Request $request, Pool $pool, int $viewerUserId): array
    {
        $raw = $request->query('compare');

        if (! is_string($raw) || trim($raw) === '') {
            return [];
        }

        $ids = collect(explode(',', $raw))
            ->map(fn (string $id): int => (int) trim($id))
            ->filter(fn (int $id): bool => $id > 0)
            ->unique()
            ->values();

        if ($ids->isEmpty()) {
            return [];
        }

        $valid = $pool->entries()
            ->whereIn('id', $ids->all())
            ->where('user_id', '!=', $viewerUserId)
            ->pluck('id')
            ->all();

        return $ids
            ->filter(fn (int $id): bool => in_array($id, $valid, true))
            ->take(3)
            ->values()
            ->all();
    }

    /**
     * Every entry in the pool as a lightweight pick-list row (no heavy prediction data), ranked by
     * points so the "Add player" picker can show and filter the whole pool. The viewer is flagged
     * so the picker can exclude their own row.
     *
     * @return list<array{entry_id: int, user_id: int, name: string, initials: string, avatar: ?string, points: ?int, rank: int, is_me: bool}>
     */
    private function playerDirectory(Pool $pool, int $userId): array
    {
        return $pool->entries()
            ->with('user:id,name,avatar_path')
            ->orderBy('id')
            ->get()
            ->sortByDesc(fn (Entry $entry): int => $entry->total_points ?? PHP_INT_MIN)
            ->values()
            ->map(fn (Entry $entry, int $index): array => [
                'entry_id' => $entry->id,
                'user_id' => $entry->user_id,
                'name' => $entry->user_id === $userId ? 'You' : ($entry->user->name ?? 'Player'),
                'initials' => $this->initials($entry->user->name ?? ''),
                'avatar' => $entry->user->avatar,
                'points' => $entry->total_points,
                'rank' => $index + 1,
                'is_me' => $entry->user_id === $userId,
            ])
            ->all();
    }

    /**
     * The Leaderboards page — every board's full table for the selected matchday. The viewer can
     * travel the tournament's matchday timeline (`?matchday=`, defaulting to the current one): the
     * table shows how each board stood at that matchday's end, and the cards show what each player
     * earned within it. The viewer's row is marked, and each board reports whether scoring has begun
     * so the page can lead with an explainer state. `active_board` preselects a tab when a valid
     * `?board=` is given (e.g. from a leaders-strip link).
     */
    public function leaderboard(Request $request, Pool $pool, MatchdayLeaderboard $matchdays): Response
    {
        $requestedMatchday = $request->query('matchday');
        $view = $matchdays->build(
            $pool,
            $request->user()->id,
            is_string($requestedMatchday) ? $requestedMatchday : null,
        );

        $requestedBoard = $request->query('board');
        $active = is_string($requestedBoard) && in_array($requestedBoard, array_column($view->boards, 'key'), true)
            ? $requestedBoard
            : null;

        return Inertia::render('pools/leaderboard', [
            // pricing gates the "Prize board" badge and feeds the inline prize amounts on Overall.
            'pool' => [
                ...$this->poolHeader($pool),
                'pricing' => PrizePot::forPool($pool, $view->participants)->toArray(),
            ],
            'boards' => $view->boards,
            'active_board' => $active,
            'matchdays' => $view->matchdays,
            'selected_matchday' => $view->selectedKey,
        ]);
    }

    /**
     * Every leaderboard for a pool, in display order, each with its full ranked table.
     *
     * @return list<array<string, mixed>>
     */
    private function boards(Pool $pool, int $userId): array
    {
        $entries = $pool->entries()
            ->with(['user', 'standings'])
            ->orderBy('id')
            ->get();

        $hasScores = $entries->contains(fn (Entry $entry): bool => $entry->total_points !== null);

        return array_map(
            fn (LeaderboardCategory $category): array => $this->board($category, $entries, $userId, $hasScores),
            LeaderboardCategory::ordered(),
        );
    }

    /**
     * One board's payload: its labels plus the entries ranked for that category. The Overall board
     * ranks by `total_points` straight off the entry (mirroring the snapshot); every other board
     * ranks its {@see LeaderboardStanding} rows by value, then tie-break, then entry id.
     *
     * @param  Collection<int, Entry>  $entries
     * @return array<string, mixed>
     */
    private function board(LeaderboardCategory $category, Collection $entries, int $userId, bool $hasScores): array
    {
        if ($category === LeaderboardCategory::Overall) {
            $rows = $entries
                ->sortByDesc(fn (Entry $entry): int => $entry->total_points ?? PHP_INT_MIN)
                ->values()
                ->map(fn (Entry $entry, int $index): array => [
                    'rank' => $index + 1,
                    'entry_id' => $entry->id,
                    'name' => $entry->user_id === $userId ? 'You' : ($entry->user->name ?? 'Player'),
                    'initials' => $this->initials($entry->user->name ?? ''),
                    'avatar' => $entry->user->avatar,
                    'primary_value' => $entry->total_points,
                    'secondary_value' => null,
                    'is_me' => $entry->user_id === $userId,
                    'movement' => $this->movement($entry->rank, $entry->previous_rank),
                    'movement_delta' => $this->movementDelta($entry->rank, $entry->previous_rank),
                ])
                ->all();
        } else {
            $rows = $entries
                ->map(fn (Entry $entry): array => [
                    'entry' => $entry,
                    'standing' => $entry->standings->firstWhere('category', $category),
                ])
                ->sort($this->compareStandings(...))
                ->values()
                ->map(fn (array $pair, int $index): array => [
                    'rank' => $index + 1,
                    'entry_id' => $pair['entry']->id,
                    'name' => $pair['entry']->user_id === $userId ? 'You' : ($pair['entry']->user->name ?? 'Player'),
                    'initials' => $this->initials($pair['entry']->user->name ?? ''),
                    'avatar' => $pair['entry']->user->avatar,
                    'primary_value' => $pair['standing']?->value ?? 0,
                    'secondary_value' => $pair['standing']?->tiebreaker ?? 0,
                    'is_me' => $pair['entry']->user_id === $userId,
                    'movement' => $this->movement($pair['standing']?->rank, $pair['standing']?->previous_rank),
                    'movement_delta' => $this->movementDelta($pair['standing']?->rank, $pair['standing']?->previous_rank),
                ])
                ->all();
        }

        return [
            'key' => $category->value,
            'label' => $category->label(),
            'description' => $category->description(),
            'primary_stat_label' => $category->primaryStatLabel(),
            'secondary_stat_label' => $category->secondaryStatLabel(),
            'awards_prizes' => $category->awardsPrizes(),
            'has_scores' => $hasScores,
            'rows' => $rows,
        ];
    }

    /**
     * Order two entry/standing pairs for a board: value descending, then tie-break descending, then
     * entry id ascending (stable). Missing standings sort as zero.
     *
     * @param  array{entry: Entry, standing: ?LeaderboardStanding}  $a
     * @param  array{entry: Entry, standing: ?LeaderboardStanding}  $b
     */
    private function compareStandings(array $a, array $b): int
    {
        return ($b['standing']?->value ?? 0) <=> ($a['standing']?->value ?? 0)
            ?: ($b['standing']?->tiebreaker ?? 0) <=> ($a['standing']?->tiebreaker ?? 0)
            ?: $a['entry']->id <=> $b['entry']->id;
    }

    /**
     * The pool page's banner scalars, derived from the (already-built) Overall board: how many
     * players, whether scoring has begun, and the viewer's own row (null when they haven't joined).
     *
     * @param  list<array<string, mixed>>  $boards
     * @return array{participants: int, has_scores: bool, me: ?array<string, mixed>}
     */
    private function poolScalars(array $boards): array
    {
        $overall = $boards[0];

        return [
            'participants' => count($overall['rows']),
            'has_scores' => $overall['has_scores'],
            'me' => collect($overall['rows'])->firstWhere('is_me', true),
        ];
    }

    /**
     * The first three boards for the pool page: the headline (Overall) as a short top-N table, and
     * the next two trimmed to just their leader (the frontend renders them as leader+you summary
     * cards). Each is truncated to its top rows plus the viewer's pinned row when they rank outside.
     *
     * @param  list<array<string, mixed>>  $boards
     * @return list<array<string, mixed>>
     */
    private function featuredBoards(array $boards): array
    {
        return collect($boards)
            ->take(3)
            ->map(fn (array $board, int $index): array => $this->truncateBoard(
                $board,
                $index === 0 ? self::HEADLINE_BOARD_ROWS : self::SECONDARY_BOARD_ROWS,
            ))
            ->values()
            ->all();
    }

    /**
     * Reduce a full board to a featured card: its top `$rows` plus the viewer's own row pinned only
     * when they rank outside the shown top (else null, since they're already visible).
     *
     * @param  array<string, mixed>  $board
     * @return array<string, mixed>
     */
    private function truncateBoard(array $board, int $rows): array
    {
        $top = array_slice($board['rows'], 0, $rows);
        $mine = collect($board['rows'])->firstWhere('is_me', true);
        $pinnedMe = $mine !== null && ! collect($top)->contains('is_me', true) ? $mine : null;

        return [
            'key' => $board['key'],
            'label' => $board['label'],
            'description' => $board['description'],
            'primary_stat_label' => $board['primary_stat_label'],
            'secondary_stat_label' => $board['secondary_stat_label'],
            'awards_prizes' => $board['awards_prizes'],
            'has_scores' => $board['has_scores'],
            'participants' => count($board['rows']),
            'top' => $top,
            'me' => $pinnedMe,
        ];
    }

    /**
     * Boards beyond the first three, as condensed summaries for the "More leaderboards" section.
     * Empty while a pool runs three or fewer boards (the case today).
     *
     * @param  list<array<string, mixed>>  $boards
     * @return list<array<string, mixed>>
     */
    private function moreBoards(array $boards): array
    {
        return collect($boards)
            ->slice(3)
            ->map($this->boardSummary(...))
            ->values()
            ->all();
    }

    /**
     * A condensed summary of one board: who leads it, and where the viewer stands on it. Both are
     * null until scoring has begun (or the viewer has no entry).
     *
     * @param  array<string, mixed>  $board
     * @return array<string, mixed>
     */
    private function boardSummary(array $board): array
    {
        $mine = $board['has_scores']
            ? collect($board['rows'])->firstWhere('is_me', true)
            : null;

        return [
            'key' => $board['key'],
            'label' => $board['label'],
            'primary_stat_label' => $board['primary_stat_label'],
            // The leader carries its entry id + is_me so compare selection can add this
            // board's winner straight from the card.
            'leader' => $board['has_scores'] && $board['rows'] !== []
                ? [
                    'entry_id' => $board['rows'][0]['entry_id'],
                    'name' => $board['rows'][0]['name'],
                    'initials' => $board['rows'][0]['initials'],
                    'avatar' => $board['rows'][0]['avatar'],
                    'primary_value' => $board['rows'][0]['primary_value'],
                    'is_me' => $board['rows'][0]['is_me'],
                ]
                : null,
            'you' => $mine === null ? null : [
                'rank' => $mine['rank'],
                'primary_value' => $mine['primary_value'],
                'movement' => $mine['movement'],
                'movement_delta' => $mine['movement_delta'],
            ],
        ];
    }

    /**
     * The board descriptors (no rows) for the "How this pool works" dialog.
     *
     * @return list<array<string, mixed>>
     */
    private function boardDescriptors(): array
    {
        return array_map(fn (LeaderboardCategory $category): array => [
            'key' => $category->value,
            'label' => $category->label(),
            'description' => $category->description(),
            'how_it_scores' => $category->howItScores(),
            'primary_stat_label' => $category->primaryStatLabel(),
            'secondary_stat_label' => $category->secondaryStatLabel(),
            'awards_prizes' => $category->awardsPrizes(),
        ], LeaderboardCategory::ordered());
    }

    /**
     * Which way a rank moved on a leaderboard since the last approved batch: up, down, same, or
     * "new" (first appearance). Null until ranks have been snapshotted at least once.
     */
    private function movement(?int $rank, ?int $previousRank): ?string
    {
        return RankMovement::direction($rank, $previousRank);
    }

    /**
     * How many places a rank moved since the last snapshot — always positive, or null when
     * there is no comparable previous rank (first appearance / before any snapshot).
     */
    private function movementDelta(?int $rank, ?int $previousRank): ?int
    {
        return RankMovement::delta($rank, $previousRank);
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

    /**
     * Shared header fields for a pool across the pools screens. The pool carries its own
     * identity (slug/name/source/accent/scoring style {@see BuildsPoolIdentity}) while the
     * lifecycle, sport and dates come from the shared competition it is played over.
     *
     * @return array{slug: string, name: string, source: string, tournament_name: string, accent: ?string, scoring_label: string, sport: string, status: string, starts_on: ?string, ends_on: ?string}
     */
    private function poolHeader(Pool $pool): array
    {
        $tournament = $pool->tournament;

        return [
            ...$this->poolIdentity($pool),
            'sport' => $tournament->sport->value,
            'status' => $tournament->status->value,
            'starts_on' => $tournament->starts_on?->toDateString(),
            'ends_on' => $tournament->ends_on?->toDateString(),
        ];
    }

    /**
     * @param  Collection<int, GroupPrediction>  $predictions
     * @param  bool  $showPredicted  whether to expose the viewer's projected table (upfront pools only)
     * @param  array<int, array{key: string, label: string, short_label: string, kind: string}>  $fixtureMatchdays
     * @return array{name: string, teams: list<array<string, mixed>>, fixtures: list<array<string, mixed>>, standings: list<array<string, mixed>>, predicted_standings?: list<array<string, mixed>>|null}
     */
    private function mapGroup(Group $group, Collection $predictions, bool $showPredicted, array $fixtureMatchdays): array
    {
        $mapped = [
            'name' => $group->name,
            'teams' => $group->teams
                ->map(fn (Team $team): array => [
                    ...$this->teamRef($team),
                    'position' => $team->pivot->position,
                ])
                ->all(),
            'fixtures' => $group->fixtures->map(function (Fixture $fixture) use ($predictions, $fixtureMatchdays): array {
                $prediction = $predictions->get($fixture->id);

                return [
                    'fixture_id' => $fixture->id,
                    'match_number' => $fixture->match_number,
                    'matchday_key' => $fixtureMatchdays[$fixture->id]['key'] ?? null,
                    'home' => $this->teamRef($fixture->homeTeam),
                    'away' => $this->teamRef($fixture->awayTeam),
                    'home_goals' => $fixture->home_goals,
                    'away_goals' => $fixture->away_goals,
                    'is_live' => $fixture->liveState?->isLive() ?? false,
                    'kicks_off_at' => $fixture->kicks_off_at?->toIso8601String(),
                    'venue' => $fixture->venue,
                    'venue_timezone' => $fixture->venue_timezone,
                    'prediction' => $prediction !== null && $prediction->home_goals !== null && $prediction->away_goals !== null
                        ? [
                            'home_goals' => $prediction->home_goals,
                            'away_goals' => $prediction->away_goals,
                            'points_awarded' => $prediction->points_awarded,
                        ]
                        : null,
                ];
            })->all(),
            'standings' => $this->officialStandings($group),
        ];

        // Phased pools predict the official bracket, so the projected group order decides nothing —
        // omit it (and its Official|Predicted toggle) rather than invite a meaningless comparison.
        if ($showPredicted) {
            $mapped['predicted_standings'] = $this->predictedStandings($group, $predictions);
        }

        return $mapped;
    }

    /**
     * The official live group table — the same FIFA-correct engine used for predicted
     * standings, fed the real (already-played) fixture scores instead of a user's picks.
     * Unplayed fixtures are skipped, so the table is seed-ordered before kick-off and
     * fills in as results land.
     *
     * @return list<array<string, mixed>>
     */
    private function officialStandings(Group $group): array
    {
        $results = $group->fixtures
            ->filter(fn (Fixture $fixture): bool => $fixture->home_goals !== null && $fixture->away_goals !== null)
            ->mapWithKeys(fn (Fixture $fixture): array => [$fixture->id => new GroupPrediction([
                'home_goals' => $fixture->home_goals,
                'away_goals' => $fixture->away_goals,
            ])])
            ->all();

        return GroupStandingsPresenter::rows(
            new GroupStandings($group, $results),
            $group->teams->keyBy('id'),
        );
    }

    /**
     * The viewer's projected group table from their own predicted scores — the same engine as
     * the official table, so the two can be compared row for row. Returns null when the viewer
     * has predicted none of this group's fixtures, so the page can show a "no prediction" state
     * rather than an all-zero table.
     *
     * @param  Collection<int, GroupPrediction>  $groupPredictions  the viewer's picks keyed by fixture id
     * @return list<array<string, mixed>>|null
     */
    private function predictedStandings(Group $group, Collection $groupPredictions): ?array
    {
        $predictions = $group->fixtures
            ->filter(fn (Fixture $fixture): bool => $groupPredictions->has($fixture->id))
            ->mapWithKeys(fn (Fixture $fixture): array => [$fixture->id => $groupPredictions->get($fixture->id)])
            ->all();

        if ($predictions === []) {
            return null;
        }

        return GroupStandingsPresenter::rows(
            new GroupStandings($group, $predictions),
            $group->teams->keyBy('id'),
        );
    }

    /**
     * Group the knockout fixtures into bracket columns, ordered by phase progression. Each
     * fixture carries the official result (teams, score, penalties, advancing team) plus the
     * viewer's own prediction and the points it earned, so a settled card can show all three.
     *
     * @param  Collection<int, Fixture>  $fixtures
     * @param  Collection<int, KnockoutPrediction>  $predictions  keyed by fixture id
     * @param  bool  $showPredictedTeams  whether to expose the viewer's predicted teams (upfront brackets only)
     * @param  array<int, array{key: string, label: string, short_label: string, kind: string}>  $fixtureMatchdays
     * @return list<array<string, mixed>>
     */
    private function mapBracket($fixtures, Collection $predictions, bool $showPredictedTeams, array $fixtureMatchdays): array
    {
        return $fixtures
            ->groupBy(fn (Fixture $fixture): string => $fixture->phase->key->value)
            ->map(fn ($phaseFixtures): array => [
                'phase_key' => $phaseFixtures->first()->phase->key->value,
                'phase_name' => $phaseFixtures->first()->phase->name,
                'sort_order' => $phaseFixtures->first()->phase->sort_order,
                'fixtures' => $phaseFixtures->map(function (Fixture $fixture) use ($predictions, $showPredictedTeams, $fixtureMatchdays): array {
                    $prediction = $predictions->get($fixture->id);

                    return [
                        'fixture_id' => $fixture->id,
                        'match_number' => $fixture->match_number,
                        'matchday_key' => $fixtureMatchdays[$fixture->id]['key'] ?? null,
                        'bracket_slot' => $fixture->bracket_slot,
                        'home' => $this->teamRef($fixture->homeTeam),
                        'away' => $this->teamRef($fixture->awayTeam),
                        'home_label' => $fixture->homeTeam?->name ?? $fixture->home_placeholder_label,
                        'away_label' => $fixture->awayTeam?->name ?? $fixture->away_placeholder_label,
                        'home_goals' => $fixture->home_goals,
                        'away_goals' => $fixture->away_goals,
                        'home_penalties' => $fixture->home_penalties,
                        'away_penalties' => $fixture->away_penalties,
                        'winner_team_id' => $fixture->winner_team_id,
                        'is_live' => $fixture->liveState?->isLive() ?? false,
                        'kicks_off_at' => $fixture->kicks_off_at?->toIso8601String(),
                        'venue' => $fixture->venue,
                        'venue_timezone' => $fixture->venue_timezone,
                        'prediction' => $prediction === null ? null : [
                            'home_goals' => $prediction->home_goals,
                            'away_goals' => $prediction->away_goals,
                            'advancing_team_id' => $prediction->advancing_team_id,
                            'points_awarded' => $prediction->points_awarded,
                            'predicted_home' => $showPredictedTeams ? $this->teamRef($prediction->predictedHomeTeam) : null,
                            'predicted_away' => $showPredictedTeams ? $this->teamRef($prediction->predictedAwayTeam) : null,
                        ],
                    ];
                })->all(),
            ])
            ->sortBy('sort_order')
            ->values()
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
