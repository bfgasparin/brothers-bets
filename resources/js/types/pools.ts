export type PoolStatus = 'upcoming' | 'in_progress' | 'completed';

/** A Laravel length-aware paginator as serialized to Inertia. */
export interface Paginated<T> {
    data: T[];
    current_page: number;
    last_page: number;
    per_page: number;
    total: number;
    prev_page_url: string | null;
    next_page_url: string | null;
}

export interface PoolSummary {
    slug: string;
    name: string;
    /** The group/source that created the pool, e.g. "Wagner Figueiredo". */
    source: string;
    /** The tournament this pool is played over (canonical English; translated at display time). */
    tournament_name: string;
    /** The pool's colour identity key (pitch|teal|gold|violet); null falls back to a positional colour. */
    accent?: string | null;
    /** Short human-readable scoring style, e.g. "Upfront Bracket". */
    scoring_label?: string;
    sport: string;
    status: PoolStatus;
    starts_on: string | null;
    ends_on: string | null;
    groups_count?: number;
    fixtures_count?: number;
}

/**
 * A pool as shown on the selection page: the summary plus how it scores, so players can tell
 * pools over the same competition apart.
 */
export interface PoolListItem extends PoolSummary {
    scoring_label: string;
    /** One-line explainer of how this pool scores — what differs from its siblings. */
    scoring_description: string;
    /** The competition this pool is played over. Sibling pools share this identity. */
    tournament: { id: number; name: string };
    /** 0-based position among the tournament's pools (by id) — drives the per-pool kit colour. */
    accent_index: number;
    /** Size of this pool (its entry count) — distinct per pool. */
    players_count: number;
    /** Whether the viewer has already joined this pool. */
    joined: boolean;
    /** Whether joining is still open — drives the card's buy-in + percentage/raw prize display. */
    can_join: boolean;
    /** Buy-in and prizes, computed from the current pool size. */
    pricing: PoolPricing;
}

/** One place's cut of the net prize pot. */
export interface PrizeAllocation {
    place: number;
    percentage: number;
    /** The amount for this place, in the pool's currency, with the house fee already removed. */
    amount: number;
}

/**
 * A pool's money side: the buy-in, the pot it accumulates from joined players, the organizer's
 * fee, and the net pot split per place. Payment is handled externally — these are display figures.
 */
export interface PoolPricing {
    currency: string;
    entry_price: number;
    players: number;
    /** entry_price × players, before the house fee. */
    pot: number;
    house_fee_percentage: number;
    /** The pot after the house fee — what the prizes are split from. */
    net: number;
    prizes: PrizeAllocation[];
}

export interface TeamRef {
    id: number;
    name: string;
    code: string | null;
    is_placeholder: boolean;
    flag_url: string;
}

export interface GroupTeam extends TeamRef {
    position: number;
}

export type FixtureStatus = 'scheduled' | 'live' | 'finished';

/** A venue an admin may reschedule a fixture into, paired with its registered IANA timezone. */
export interface VenueOption {
    name: string;
    timezone: string;
}

/** One fixture as listed on the admin "Manage schedule" screen. */
export interface ScheduleFixtureRow {
    id: number;
    match_number: number;
    phase: string;
    is_knockout: boolean;
    status: FixtureStatus;
    kicks_off_at: string | null;
    venue: string | null;
    venue_timezone: string | null;
    home: TeamRef | null;
    away: TeamRef | null;
    home_label: string | null;
    away_label: string | null;
    /** Whether this fixture's kickoff currently sets a prediction deadline (warn before moving). */
    governs_prediction_lock: boolean;
}

/**
 * A lightweight matchday in the tournament's timeline (group rounds MD1-3, then one per knockout
 * phase). The pool page's Matchdays/Schedule views and the per-match markers resolve labels and
 * order from this. Display-only — unlike {@see LeaderboardMatchday}, it carries no travel status.
 */
export interface MatchdayDescriptor {
    key: string;
    label: string;
    short_label: string;
    kind: 'group' | 'knockout';
}

export interface GroupFixture {
    fixture_id: number;
    match_number: number;
    /** The matchday this fixture belongs to (e.g. "group-1", "round_of_16"); keys a MatchdayDescriptor. */
    matchday_key: string | null;
    home: TeamRef | null;
    away: TeamRef | null;
    home_goals: number | null;
    away_goals: number | null;
    /** Whether the match is being played live right now (drives the in-play marker). */
    is_live: boolean;
    kicks_off_at: string | null;
    venue: string | null;
    venue_timezone: string | null;
    /** The viewer's own predicted scoreline, if they've made one. */
    prediction: {
        home_goals: number;
        away_goals: number;
        /** Points earned once the official result is in (null while unscored). */
        points_awarded: number | null;
    } | null;
}

export interface GroupView {
    name: string;
    teams: GroupTeam[];
    fixtures: GroupFixture[];
    /** Official live group table, computed from real match results. */
    standings: StandingRow[];
    /**
     * The viewer's projected group table from their own predicted scores, or null when they
     * have predicted none of this group's fixtures. Absent on phased pools, where the projected
     * order decides nothing — the standings then show official-only.
     */
    predicted_standings?: StandingRow[] | null;
}

export interface BracketFixture {
    fixture_id: number;
    match_number: number;
    /** The matchday this fixture belongs to (a knockout phase key); keys a MatchdayDescriptor. */
    matchday_key: string | null;
    bracket_slot: string | null;
    home: TeamRef | null;
    away: TeamRef | null;
    home_label: string | null;
    away_label: string | null;
    home_goals: number | null;
    away_goals: number | null;
    home_penalties: number | null;
    away_penalties: number | null;
    /** The official advancing team once the match is settled. */
    winner_team_id: number | null;
    /** Whether the match is being played live right now (drives the in-play marker). */
    is_live: boolean;
    kicks_off_at: string | null;
    venue: string | null;
    venue_timezone: string | null;
    /** The viewer's own pick for this knockout match, if they made one. */
    prediction: {
        home_goals: number | null;
        away_goals: number | null;
        advancing_team_id: number | null;
        points_awarded: number | null;
        /** The teams the viewer predicted for this match (upfront-bracket tournaments only). */
        predicted_home: TeamRef | null;
        predicted_away: TeamRef | null;
    } | null;
}

export interface BracketPhase {
    phase_key: string;
    phase_name: string;
    sort_order: number;
    fixtures: BracketFixture[];
}

/** How and when to fill in predictions for a pool's scoring strategy. */
export interface HowToPlay {
    summary: string;
    steps: string[];
}

export interface PoolDetail extends PoolSummary {
    scoring_strategy: string;
    scoring_label: string;
    scoring_description: string;
    how_to_play: HowToPlay;
    scoring_config: Record<string, Record<string, number>>;
    /** When the group-stage predictions lock (ISO 8601): derived from the first group kickoff (minus the buffer) or an explicit override; null when there is no schedule to derive from. */
    predictions_lock_at: string | null;
    /** Whether the viewer may still join the pool (the join window closes with the prediction lock). */
    can_join: boolean;
    /** Whether this viewer has already seen the "how it works" briefing, so it only auto-opens on their first visit. */
    has_seen_briefing: boolean;
    /** Buy-in and prizes, computed from the current pool size. */
    pricing: PoolPricing;
    /** Board descriptors for the "How this pool works" dialog. */
    leaderboards: BoardDescriptor[];
}

// --- Leaderboards ---

/** Movement on a leaderboard since the last approved results, or "new" on first appearance. */
export type RankMovement = 'up' | 'down' | 'same' | 'new';

/** The string key of each leaderboard (matches the backend LeaderboardCategory enum). */
export type LeaderboardCategoryKey =
    | 'overall'
    | 'match-winners'
    | 'goal-sniper';

/** The pool page banner scalars, derived from the Overall board. */
export interface PoolStandings {
    participants: number;
    has_scores: boolean;
    /** The viewer's own row on the Overall board, or null when they haven't joined. */
    me: BoardRow | null;
}

/** One open prediction window with unfinished work, for the pool page's reminder banner. */
export interface AttentionWindow {
    phase_key: string;
    /** Human label for the window, e.g. "Group stage" or "Round of 16". */
    label: string;
    /** ISO 8601 instant the window closes, or null when no deadline is scheduled yet. */
    deadline: string | null;
    /** Predictions still to make in this window. */
    missing_count: number;
    total_count: number;
    /** Upfront pools only: complete picks that still leave a tie the player must break. */
    has_unresolved_ties: boolean;
}

/**
 * What prediction work the viewer still has open in a pool — the same signal as the sidebar dot,
 * with the per-window detail the reminder banner lists. `needs_attention` is false (and the list
 * empty) when nothing is outstanding or the viewer hasn't joined.
 */
export interface AttentionSummary {
    needs_attention: boolean;
    open_windows: AttentionWindow[];
}

/** One currently-open window the viewer has finished, for the "all set" celebration. */
export interface CompletionWindow {
    phase_key: string;
    /** Human label, e.g. "Group stage", "Round of 16", or "Your bracket" (upfront pools). */
    label: string;
    /** ISO 8601 instant the window locks, or null when no deadline is scheduled yet. */
    deadline: string | null;
}

/**
 * Whether the viewer has completed every prediction in a currently-open window — the inverse of
 * {@link AttentionSummary}. Drives the predict page's celebration modal (on the session
 * incomplete→complete transition) and its calm "all set" banner. `is_complete` is false when work
 * remains OR when no window is currently open (nothing to celebrate), in which case `open_windows`
 * is empty; when true it lists the finished window(s) so the message can name them and their lock.
 */
export interface CompletionSummary {
    is_complete: boolean;
    open_windows: CompletionWindow[];
}

/** A ranked row on any of the full leaderboards (Leaderboards page). */
export interface BoardRow {
    rank: number;
    /** The entry behind this row, so it can be added to a player comparison. */
    entry_id: number;
    name: string;
    initials: string;
    avatar: string | null;
    /** The board's headline value; null renders as "—" and suppresses podium styling. */
    primary_value: number | null;
    /** The board's tie-break value, or null for boards that don't show one (Overall). */
    secondary_value: number | null;
    is_me: boolean;
    movement: RankMovement | null;
    /** How many places this row moved since the last results, or null when not comparable. */
    movement_delta: number | null;
}

/** One player's headline number for a single matchday — a card on the Leaderboards page. */
export interface MatchdayStat {
    entry_id: number;
    name: string;
    initials: string;
    avatar: string | null;
    /** The board's metric earned within the matchday (points / winners / team goals). */
    value: number;
    is_me: boolean;
}

/**
 * A tie-aware matchday card: every tied leader (the avatar stack slices to three; the detail dialog
 * lists them all) plus their count. A unique leader has `count === 1`; all leaders share the same
 * `value`.
 */
export interface MatchdayCard {
    leaders: MatchdayStat[];
    count: number;
}

/**
 * The per-matchday cards for the selected matchday on the active board. `you`/`top`/`lowest` carry
 * the metric earned; `biggest_climber`/`biggest_faller` carry places moved on the board (their
 * `value` is the number of places). `you` is the single viewer stat; the rest are tie-aware cards.
 */
export interface MatchdayCards {
    you: MatchdayStat | null;
    top: MatchdayCard | null;
    lowest: MatchdayCard | null;
    biggest_climber: MatchdayCard | null;
    biggest_faller: MatchdayCard | null;
}

export interface LeaderboardBoard {
    key: LeaderboardCategoryKey;
    label: string;
    description: string;
    primary_stat_label: string;
    secondary_stat_label: string | null;
    /** Whether finishing top of this board wins a share of the prize pot (Overall only). */
    awards_prizes: boolean;
    has_scores: boolean;
    rows: BoardRow[];
    /** Per-matchday cards for this board, scoped to the selected matchday. */
    matchday_stats: MatchdayCards;
}

/**
 * A stop on the leaderboard's matchday timeline: a group-stage round or a knockout phase. Times are
 * UTC ISO strings; render them in the viewer's zone.
 */
export interface LeaderboardMatchday {
    key: string;
    label: string;
    short_label: string;
    kind: 'group' | 'knockout';
    status: 'complete' | 'in_progress' | 'upcoming';
    is_current: boolean;
    starts_at: string | null;
    ends_at: string | null;
}

/**
 * One of the first three boards shown as a full table on the pool page: its top rows plus the
 * viewer's own row pinned when they rank outside them.
 */
export interface FeaturedBoard {
    key: LeaderboardCategoryKey;
    label: string;
    description: string;
    primary_stat_label: string;
    secondary_stat_label: string | null;
    /** Whether finishing top of this board wins a share of the prize pot (Overall only). */
    awards_prizes: boolean;
    has_scores: boolean;
    /** Total players on the board (the `top` rows may be a truncated view of these). */
    participants: number;
    /** The board's top rows (at most the per-board cap). */
    top: BoardRow[];
    /** The viewer's own row, pinned only when they rank outside `top`; null otherwise. */
    me: BoardRow | null;
}

/** A board summary for the pool page: who leads it, and where the viewer stands on it. */
export interface BoardSummary {
    key: LeaderboardCategoryKey;
    label: string;
    primary_stat_label: string;
    leader: {
        /** The entry behind the leader, so it can be added to a comparison from the card. */
        entry_id: number;
        name: string;
        initials: string;
        avatar: string | null;
        primary_value: number | null;
        is_me: boolean;
    } | null;
    you: {
        rank: number;
        primary_value: number | null;
        movement: RankMovement | null;
        /** How many places the viewer moved since the last results, or null when not comparable. */
        movement_delta: number | null;
    } | null;
}

/** A board's name + blurb (no rows), for the "How this pool works" dialog. */
export interface BoardDescriptor {
    key: LeaderboardCategoryKey;
    label: string;
    description: string;
    /** A fuller explanation with a worked example, shown only in the dialog. */
    how_it_scores: string;
    primary_stat_label: string;
    secondary_stat_label: string | null;
    /** Whether finishing top of this board wins a share of the prize pot (Overall only). */
    awards_prizes: boolean;
}

export interface LeaderboardPageProps {
    /** The pool header plus its pricing — gates the "Prize board" badge and the inline amounts. */
    pool: PoolSummary & { pricing: PoolPricing };
    boards: LeaderboardBoard[];
    active_board: LeaderboardCategoryKey | null;
    /** The matchday timeline the viewer can travel along. */
    matchdays: LeaderboardMatchday[];
    /** The key of the currently selected matchday (server-validated, defaults to current). */
    selected_matchday: string;
}

// --- Compare players ---

/** A pick-list row for the "Add player" picker — every entry in the pool, no heavy data. */
export interface PlayerDirectoryEntry {
    entry_id: number;
    user_id: number;
    name: string;
    initials: string;
    avatar: string | null;
    points: number | null;
    rank: number;
    is_me: boolean;
}

/** One player's gated group-stage scoreline in a comparison, keyed by fixture id. */
export interface CompareGroupPrediction {
    home_goals: number;
    away_goals: number;
    points_awarded: number | null;
}

/** One player's gated knockout pick in a comparison, keyed by fixture id. */
export interface CompareKnockoutPrediction {
    home_goals: number | null;
    away_goals: number | null;
    advancing_team_id: number | null;
    points_awarded: number | null;
    /** The teams the player predicted for this match (upfront-bracket pools only). */
    predicted_home: TeamRef | null;
    predicted_away: TeamRef | null;
}

/**
 * One lane in a comparison. Predictions are present only for fixtures revealable to the viewer
 * (the player's own lane, or fixtures whose window has locked); a missing key means hidden-by-lock
 * (when its window is not `locked`) or no-prediction (when its window is `locked`). Points, rank and
 * board totals are always present.
 */
export interface ComparePlayer {
    entry_id: number | null;
    user_id: number | null;
    name: string;
    initials: string;
    avatar: string | null;
    is_viewer: boolean;
    total_points: number | null;
    rank: number | null;
    boards: { key: LeaderboardCategoryKey; primary_value: number | null }[];
    group_predictions: Record<number, CompareGroupPrediction>;
    knockout_predictions: Record<number, CompareKnockoutPrediction>;
    /** Projected group table from this player's picks, keyed by group name; null when hidden or unpredicted. */
    projected_standings: Record<string, StandingRow[] | null>;
}

export interface Comparison {
    /** Each phase's prediction window, so the UI can show the right empty state per fixture. */
    windows: Record<string, PredictionWindowStatus>;
    /** The viewer (lane 0) followed by the selected opponents, in selection order. */
    players: ComparePlayer[];
}

// --- Prediction wizard ---

export interface StandingRow {
    rank: number;
    team: TeamRef | null;
    played: number;
    won: number;
    drawn: number;
    lost: number;
    goals_for: number;
    goals_against: number;
    goal_difference: number;
    points: number;
    form: string[];
    /**
     * Phased-pool predict page only: the row is part of a genuine tie (teams level on every
     * tiebreaker), so the projected order is just a fallback. Unset elsewhere.
     */
    tied?: boolean;
}

export interface PredictGroupFixture {
    fixture_id: number;
    match_number: number;
    /** The matchday this fixture belongs to (e.g. "group-1"); marks each row in the wizard. */
    matchday_key: string | null;
    home: TeamRef | null;
    away: TeamRef | null;
    home_goals: number | null;
    away_goals: number | null;
    kicks_off_at: string | null;
    venue: string | null;
    venue_timezone: string | null;
}

export interface PredictGroup {
    name: string;
    teams: GroupTeam[];
    fixtures: PredictGroupFixture[];
    standings: StandingRow[];
    /**
     * Clusters of teams the ranking engine could not separate (level on every tiebreaker), each in
     * its current effective order with whether a saved ordering already resolves it. The player
     * drags these into order; empty when none. Only for upfront-bracket pools, whose bracket is
     * derived from these standings.
     */
    tied_clusters: TiedCluster[];
}

/** A tied set the player/admin must order, plus whether a saved ordering already resolves it. */
export interface TiedCluster {
    team_ids: number[];
    resolved: boolean;
}

export interface KnockoutPredictionFixture {
    fixture_id: number;
    match_number: number;
    bracket_slot: string | null;
    phase_key: string;
    home: TeamRef | null;
    away: TeamRef | null;
    home_label: string | null;
    away_label: string | null;
    home_goals: number | null;
    away_goals: number | null;
    advancing_team_id: number | null;
}

/**
 * Whether a phase currently accepts predictions: `open` is editable now, `locked` has closed
 * (kicked off / past the lock), `pending` is a phased knockout round whose real teams aren't known
 * yet so it hasn't opened.
 */
export type PredictionWindowStatus = 'open' | 'locked' | 'pending';

export interface PredictBracketPhase {
    phase_key: string;
    phase_name: string;
    sort_order: number;
    /** This round's prediction window state. */
    window: PredictionWindowStatus;
    fixtures: KnockoutPredictionFixture[];
}

export interface ThirdRanking {
    rank: number;
    team: TeamRef | null;
}

export interface PredictPoolDetail {
    slug: string;
    name: string;
    /** The group/source that created the pool, e.g. "Wagner Figueiredo". */
    source: string;
    /** The tournament this pool is played over (canonical English; translated at display time). */
    tournament_name: string;
    /** The pool's colour identity key (pitch|teal|gold|violet); null falls back to a positional colour. */
    accent?: string | null;
    /** Short human-readable scoring style, e.g. "Upfront Bracket". */
    scoring_label?: string;
    sport: string;
    status: PoolStatus;
    /** Which scoring strategy this pool uses (e.g. 'upfront-bracket', 'phased-bracket'). */
    scoring_strategy: string;
    starts_on: string | null;
    ends_on: string | null;
    predictions_lock_at: string | null;
    /** Whether the group stage (and, for upfront pools, the whole bracket) accepts edits. */
    can_edit: boolean;
    scoring_config: Record<string, Record<string, number>>;
}

/**
 * A sibling pool of the same tournament whose predictions the user can copy into this pool's
 * currently-open window(s). Surfaced on the predict wizard so the user can import instead of
 * re-entering identical picks.
 */
export interface ImportSource {
    slug: string;
    name: string;
    /** The group/source that created the pool, e.g. "Wagner Figueiredo". */
    source: string;
    /** The pool's colour identity key; null falls back to a positional colour. */
    accent?: string | null;
    /** Short human-readable scoring style, e.g. "Upfront Bracket". */
    scoring_label?: string;
    /** Display names of the phases that would be imported, e.g. ["Group Stage"]. */
    phase_labels: string[];
    /** How many of the user's own predictions would be copied — a completeness hint. */
    predictions_count: number;
}

export interface PredictPageProps {
    pool: PredictPoolDetail;
    groups: PredictGroup[];
    bracket: PredictBracketPhase[];
    thirds: ThirdRanking[] | null;
    /**
     * The third-placed teams whose tie straddles the qualifying cut and must be ordered before the
     * best-third slots can fill (effective order + whether a saved ordering resolves it); null when
     * there is no such tie.
     */
    thirds_tie: { teams: TeamRef[]; resolved: boolean } | null;
    /** Sibling pools the user can copy predictions from for the currently-open window(s). */
    import_sources: ImportSource[];
    /** Whether to nudge the user to import (an open window is empty and a source can fill it). */
    should_suggest_import: boolean;
    /** Whether this (phased) pool's standings hold a tie that has no effect, so we explain it. */
    show_tie_note: boolean;
    /** Whether every prediction in a currently-open window is done — drives the celebration. */
    completion: CompletionSummary;
}
