import { Head, usePage } from '@inertiajs/react';
import {
    ArrowDown,
    ArrowUp,
    Check,
    ChevronDown,
    ChevronRight,
    Crown,
    Radio,
    TrendingDown,
} from 'lucide-react';
import type { LucideIcon } from 'lucide-react';
import { useState } from 'react';
import { AvatarStack } from '@/components/avatar-stack';
import { KnockoutPickMatchup, PointsBadge } from '@/components/fixtures';
import { Flag } from '@/components/flag';
import type { LeaderboardEntry } from '@/components/leaderboard-row';
import { LiveBadge, LivePulse } from '@/components/live-badge';
import { PersonalMovement } from '@/components/personal-movement';
import PlayerAvatar from '@/components/player-avatar';
import { PoolIdentity } from '@/components/pool-identity';
import { StandingsList } from '@/components/standings-list';
import StatDetailDialog from '@/components/stat-detail-dialog';
import type { StatDetailPlayer } from '@/components/stat-detail-dialog';
import {
    Dialog,
    DialogContent,
    DialogDescription,
    DialogHeader,
    DialogTitle,
} from '@/components/ui/dialog';
import { SegmentedTabs } from '@/components/ui/segmented-tabs';
import {
    Sheet,
    SheetClose,
    SheetContent,
    SheetDescription,
    SheetHeader,
    SheetTitle,
    SheetTrigger,
} from '@/components/ui/sheet';
import { useLivePoll } from '@/hooks/use-live-poll';
import { useTranslation } from '@/hooks/use-translation';
import { resolveAccent, sourceMonogram } from '@/lib/accents';
import { formatMoney } from '@/lib/money';
import { formatPlaceholderLabel } from '@/lib/placeholder-label';
import { cn } from '@/lib/utils';
import live from '@/routes/live';
import type {
    FixturePick,
    LiveBoardDescriptor,
    LiveFixture,
    LivePool,
    ProjectedRow,
} from '@/types/live';

interface LiveShowProps {
    tournament: { name: string; slug: string };
    boards: LiveBoardDescriptor[];
    pools: LivePool[];
    liveFixtures: LiveFixture[];
    poll_interval_ms: number;
}

// Stable reference: only the live-changing props are re-fetched each poll.
const LIVE_ONLY = ['liveFixtures', 'pools'];

/** "2 – 1", or a dash placeholder before a score is entered. */
function score(value: number | null): string {
    return value === null ? '–' : String(value);
}

function TeamSide({
    name,
    label,
    flagTeam,
    align,
}: {
    name: string | null;
    label: string | null;
    flagTeam: LiveFixture['home_team'];
    align: 'start' | 'end';
}) {
    const { t, tCountry, tBracket } = useTranslation();
    const displayName = name ? tCountry(flagTeam?.code, name) : null;
    const displayLabel = label ? formatPlaceholderLabel(label, tBracket) : null;

    return (
        <div
            className={cn(
                'flex min-w-0 flex-1 items-center gap-2',
                align === 'end' && 'flex-row-reverse text-right',
            )}
        >
            <Flag team={flagTeam} className="size-5" />
            <span className="truncate font-display text-sm font-semibold sm:text-base">
                {displayName ?? displayLabel ?? t('TBD')}
            </span>
        </div>
    );
}

/** The flags + big scoreline shared by the live card and the picks sheet's pinned header. */
function FixtureScoreline({ fixture }: { fixture: LiveFixture }) {
    return (
        <div className="flex items-center gap-3">
            <TeamSide
                name={fixture.home_team?.name ?? null}
                label={fixture.home_label}
                flagTeam={fixture.home_team}
                align="start"
            />
            <div className="flex shrink-0 items-center gap-2 font-display text-2xl font-bold tabular-nums">
                <span>{score(fixture.home_goals)}</span>
                <span className="text-muted-foreground">:</span>
                <span>{score(fixture.away_goals)}</span>
            </div>
            <TeamSide
                name={fixture.away_team?.name ?? null}
                label={fixture.away_label}
                flagTeam={fixture.away_team}
                align="end"
            />
        </div>
    );
}

/** A small neutral "0" badge matching PointsBadge's resting shape (kept calm — not destructive). */
function ZeroPoints() {
    return (
        <span className="inline-flex items-center rounded-full bg-secondary px-2.5 py-1 font-display text-xs font-semibold text-muted-foreground tabular-nums">
            0
        </span>
    );
}

/**
 * The "everyone's picks" bottom sheet for one live match: the live score pinned on top, then every
 * player's predicted scoreline and the points they're earning from it now, sorted by points then by
 * how close the call is. Identity is joined from the pool's overall board via entry_id. Scoped to
 * the selected pool (its name labels the sheet when the viewer follows more than one).
 */
function FixturePicksSheet({
    open,
    onOpenChange,
    fixture,
    picks,
    pool,
    meId,
    multiPool,
}: {
    open: boolean;
    onOpenChange: (open: boolean) => void;
    fixture: LiveFixture;
    picks: FixturePick[];
    pool: LivePool;
    meId: number;
    multiPool: boolean;
}) {
    const { t } = useTranslation();
    const rowByEntry = new Map(
        (pool.boards.overall ?? []).map((row) => [row.entry_id, row]),
    );

    const closeness = (pick: FixturePick): number => {
        if (
            pick.home_goals === null ||
            pick.away_goals === null ||
            fixture.home_goals === null ||
            fixture.away_goals === null
        ) {
            return Number.MAX_SAFE_INTEGER;
        }

        return (
            Math.abs(pick.home_goals - fixture.home_goals) +
            Math.abs(pick.away_goals - fixture.away_goals)
        );
    };

    const sorted = [...picks].sort(
        (a, b) =>
            b.points - a.points ||
            closeness(a) - closeness(b) ||
            a.entry_id - b.entry_id,
    );

    return (
        <Dialog open={open} onOpenChange={onOpenChange}>
            <DialogContent className="gap-0 p-0 sm:max-w-md">
                <DialogHeader className="min-w-0 gap-3 border-b border-border p-4 pr-12 text-left">
                    <div className="flex items-center justify-between gap-3">
                        <DialogTitle className="min-w-0 flex-1 truncate font-display text-sm font-semibold">
                            {multiPool
                                ? `${t('All picks')} · ${pool.name}`
                                : t('All picks')}
                        </DialogTitle>
                        {fixture.status === 'ended' ? (
                            <LiveBadge
                                label={t('Full time')}
                                tone="ft"
                                className="shrink-0"
                            />
                        ) : (
                            <LiveBadge className="shrink-0" />
                        )}
                    </div>
                    <DialogDescription className="sr-only">
                        {t(
                            "Every player's prediction for this match and the points it's earning now.",
                        )}
                    </DialogDescription>
                    <FixtureScoreline fixture={fixture} />
                </DialogHeader>

                <div className="max-h-[55dvh] overflow-y-auto p-2 pb-[calc(0.5rem+env(safe-area-inset-bottom,0px))]">
                    {sorted.length > 0 ? (
                        <ul className="flex flex-col">
                            {sorted.map((pick) => {
                                const row = rowByEntry.get(pick.entry_id);
                                const isMe = row?.user_id === meId;
                                // Upfront knockout picks carry the predicted teams (which may differ
                                // from the real match-up), so render the full predicted fixture.
                                const showMatchup =
                                    pick.predicted_home != null ||
                                    pick.predicted_away != null;
                                const advancing =
                                    fixture.is_knockout &&
                                    pick.advancing_team_id != null
                                        ? ([
                                              fixture.home_team,
                                              fixture.away_team,
                                          ].find(
                                              (team) =>
                                                  team?.id ===
                                                  pick.advancing_team_id,
                                          ) ?? null)
                                        : null;

                                return (
                                    <li
                                        key={pick.entry_id}
                                        className={cn(
                                            'flex items-center gap-3 rounded-xl px-2 py-2',
                                            isMe && 'bg-primary/5',
                                        )}
                                    >
                                        <PlayerAvatar
                                            name={row?.name ?? ''}
                                            initials={row?.initials ?? '?'}
                                            src={row?.avatar ?? null}
                                            className="size-8"
                                        />
                                        <span className="min-w-0 flex-1 truncate font-display text-sm font-semibold">
                                            {isMe
                                                ? t('You')
                                                : (row?.name ?? t('Player'))}
                                        </span>
                                        {showMatchup ? (
                                            <span className="shrink-0">
                                                <KnockoutPickMatchup
                                                    homeGoals={pick.home_goals}
                                                    awayGoals={pick.away_goals}
                                                    advancingTeamId={
                                                        pick.advancing_team_id
                                                    }
                                                    predictedHome={
                                                        pick.predicted_home
                                                    }
                                                    predictedAway={
                                                        pick.predicted_away
                                                    }
                                                />
                                            </span>
                                        ) : (
                                            <>
                                                {advancing && (
                                                    <Flag
                                                        team={advancing}
                                                        className="size-4"
                                                    />
                                                )}
                                                <span className="font-display text-sm font-semibold tabular-nums">
                                                    {score(pick.home_goals)}–
                                                    {score(pick.away_goals)}
                                                </span>
                                            </>
                                        )}
                                        {pick.points > 0 ? (
                                            <PointsBadge points={pick.points} />
                                        ) : (
                                            <ZeroPoints />
                                        )}
                                    </li>
                                );
                            })}
                        </ul>
                    ) : (
                        <p className="p-6 text-center text-sm text-muted-foreground">
                            {t('No predictions yet for this match.')}
                        </p>
                    )}
                </div>
            </DialogContent>
        </Dialog>
    );
}

/**
 * One live match: big scoreline, flags, a pulsing LIVE / muted FT marker, the viewer's own pick (for
 * the selected pool), and a button opening the "all picks" sheet. Pick data is pool-scoped, joined
 * from the pool's board by entry_id; switching pools re-renders the card with the new pick.
 */
function LiveFixtureCard({
    fixture,
    pool,
    meId,
    multiPool,
}: {
    fixture: LiveFixture;
    pool: LivePool | null;
    meId: number;
    multiPool: boolean;
}) {
    const { t } = useTranslation();
    const [picksOpen, setPicksOpen] = useState(false);
    const ended = fixture.status === 'ended';

    const picks = pool?.fixture_picks[fixture.id] ?? [];
    const myEntryId =
        pool?.boards.overall?.find((row) => row.user_id === meId)?.entry_id ??
        null;
    const myPick =
        myEntryId != null
            ? (picks.find((pick) => pick.entry_id === myEntryId) ?? null)
            : null;
    // Upfront knockout picks carry the predicted teams, so show the full predicted match-up.
    const myShowMatchup =
        myPick != null &&
        (myPick.predicted_home != null || myPick.predicted_away != null);

    return (
        <div
            className={cn(
                'card-elevated flex min-w-0 flex-col gap-3 rounded-2xl border border-border p-4',
                !ended && 'ring-1 ring-red-500/20',
            )}
        >
            <div className="flex items-center justify-between">
                {ended ? (
                    <LiveBadge label={t('Full time')} tone="ft" />
                ) : (
                    <LiveBadge />
                )}
                {fixture.is_knockout && (
                    <span className="font-display text-[0.65rem] font-bold tracking-[0.14em] text-muted-foreground uppercase">
                        {t('Knockout')}
                    </span>
                )}
            </div>

            <FixtureScoreline fixture={fixture} />

            {ended && (
                <p className="text-center text-xs text-muted-foreground">
                    {t('Awaiting official confirmation')}
                </p>
            )}

            {pool && (
                <div className="flex flex-col gap-2 border-t border-border/60 pt-3">
                    {myPick && (myShowMatchup || myPick.home_goals !== null) ? (
                        <div className="flex items-center justify-center gap-2 text-xs text-muted-foreground">
                            <span className="font-semibold">
                                {t('Your pick')}
                            </span>
                            {myShowMatchup ? (
                                <KnockoutPickMatchup
                                    homeGoals={myPick.home_goals}
                                    awayGoals={myPick.away_goals}
                                    advancingTeamId={myPick.advancing_team_id}
                                    predictedHome={myPick.predicted_home}
                                    predictedAway={myPick.predicted_away}
                                />
                            ) : (
                                <span className="font-display font-semibold text-foreground tabular-nums">
                                    {score(myPick.home_goals)}–
                                    {score(myPick.away_goals)}
                                </span>
                            )}
                            {myPick.points > 0 && (
                                <PointsBadge points={myPick.points} />
                            )}
                        </div>
                    ) : (
                        <p className="text-center text-xs text-muted-foreground/70">
                            {t('No prediction')}
                        </p>
                    )}

                    <button
                        type="button"
                        onClick={() => setPicksOpen(true)}
                        className="press rounded-xl border border-border py-2 font-display text-xs font-semibold text-muted-foreground transition-colors hover:bg-secondary hover:text-foreground"
                    >
                        {t('See all picks')}
                    </button>

                    <FixturePicksSheet
                        open={picksOpen}
                        onOpenChange={setPicksOpen}
                        fixture={fixture}
                        picks={picks}
                        pool={pool}
                        meId={meId}
                        multiPool={multiPool}
                    />
                </div>
            )}
        </div>
    );
}

function LiveScoreboard({
    fixtures,
    pool,
    meId,
    multiPool,
}: {
    fixtures: LiveFixture[];
    pool: LivePool | null;
    meId: number;
    multiPool: boolean;
}) {
    const { t } = useTranslation();

    if (fixtures.length === 0) {
        return (
            <div className="card-elevated flex flex-col items-center gap-2 rounded-2xl border border-border p-10 text-center">
                <Radio className="size-8 text-muted-foreground" />
                <p className="font-display font-semibold">
                    {t('No matches are live right now')}
                </p>
                <p className="text-sm text-muted-foreground">
                    {t('This page lights up the moment a match kicks off.')}
                </p>
            </div>
        );
    }

    return (
        <div className="grid gap-3 sm:grid-cols-2 xl:grid-cols-3">
            {fixtures.map((fixture) => (
                <LiveFixtureCard
                    key={fixture.id}
                    fixture={fixture}
                    pool={pool}
                    meId={meId}
                    multiPool={multiPool}
                />
            ))}
        </div>
    );
}

/** The "+N" / "—" the viewer is gaining from the current live scores (raw signed when negative). */
function formatLiveGain(gain: number): string {
    if (gain > 0) {
        return `+${gain.toLocaleString()}`;
    }

    return gain === 0 ? '—' : gain.toLocaleString();
}

/** One labelled stat in the viewer card's grid. */
function StatCell({
    label,
    value,
    valueClassName,
}: {
    label: string;
    value: string;
    valueClassName?: string;
}) {
    return (
        <div>
            <div className="truncate text-[11px] font-bold tracking-[0.1em] text-white/70 uppercase">
                {label}
            </div>
            <div
                className={cn(
                    'font-display text-lg font-semibold tabular-nums',
                    valueClassName,
                )}
            >
                {value}
            </div>
        </div>
    );
}

/**
 * The viewer's own projected line — the focal "what would happen to me" card. Mirrors the leaderboard
 * page's branded "Your standing" card (gradient + glow, big rank, fixed stat grid) so the live and
 * official surfaces read as one family, and leads with what they're earning from the live scores. On
 * mobile the prize moves up beside the rank so the stat grid stays a tidy two columns.
 */
function ViewerProjection({
    row,
    pool,
    primaryLabel,
    className,
}: {
    row: ProjectedRow;
    pool: LivePool;
    primaryLabel: string;
    className?: string;
}) {
    const { t } = useTranslation();
    const prizeText =
        pool.is_paid && row.projected_prize != null
            ? formatMoney(row.projected_prize, pool.currency)
            : null;

    return (
        <div
            className={cn(
                'bg-brand-gradient shadow-glow relative flex flex-col overflow-hidden rounded-2xl p-5 text-white',
                className,
            )}
        >
            <div className="text-xs font-bold tracking-[0.12em] text-white/80 uppercase">
                {t('Your projected spot')}
            </div>

            <div className="flex flex-1 items-center gap-4 py-4">
                <PlayerAvatar
                    name={row.name}
                    initials={row.initials}
                    src={row.avatar}
                    fallbackClassName="bg-white/15 text-white"
                    ringClassName="ring-2 ring-white/40"
                    className="size-14"
                />
                <div className="flex items-baseline gap-2">
                    <span className="font-display text-5xl leading-none font-semibold tabular-nums">
                        #{row.rank}
                    </span>
                    <PersonalMovement
                        movement={row.movement}
                        delta={row.movement_delta}
                    />
                </div>

                {prizeText && (
                    <span className="bg-gold-gradient ml-auto rounded-full px-3 py-1 font-display text-sm font-bold text-[#3a2600] tabular-nums lg:hidden">
                        {prizeText}
                    </span>
                )}
            </div>

            <div className="grid grid-cols-2 gap-3 border-t border-white/15 pt-3">
                <StatCell
                    label={t('Projected :stat', {
                        stat: primaryLabel.toLowerCase(),
                    })}
                    value={row.primary_value.toLocaleString()}
                />
                <StatCell
                    label={t('Earning now')}
                    value={formatLiveGain(row.live_gain)}
                />

                {prizeText && (
                    <div className="hidden lg:block">
                        <div className="text-[11px] font-bold tracking-[0.1em] text-white/70 uppercase">
                            {t('Projected prize')}
                        </div>
                        <div className="mt-0.5">
                            <span className="bg-gold-gradient inline-block rounded-full px-2.5 py-0.5 font-display text-sm font-bold text-[#3a2600] tabular-nums">
                                {prizeText}
                            </span>
                        </div>
                    </div>
                )}

                {row.pending_bonus > 0 && (
                    <StatCell
                        label={t('If it holds')}
                        value={`+${row.pending_bonus}`}
                        valueClassName="text-amber-300"
                    />
                )}
            </div>
        </div>
    );
}

/**
 * The set of rows sharing the extreme qualifying metric (the tie), or null when none qualify.
 * `prefer` picks the highest value (top earner / biggest mover) or the lowest (quietest earner).
 */
function pickLeaders(
    rows: ProjectedRow[],
    metric: (row: ProjectedRow) => number,
    qualifies: (row: ProjectedRow) => boolean,
    prefer: 'high' | 'low',
): { leaders: ProjectedRow[]; value: number } | null {
    let value = 0;
    let leaders: ProjectedRow[] = [];

    for (const row of rows) {
        if (!qualifies(row)) {
            continue;
        }

        const candidate = metric(row);
        const isBetter =
            leaders.length === 0 ||
            (prefer === 'high' ? candidate > value : candidate < value);

        if (isBetter) {
            value = candidate;
            leaders = [row];
        } else if (candidate === value) {
            leaders.push(row);
        }
    }

    return leaders.length > 0 ? { leaders, value } : null;
}

/**
 * One live-mover stat card: a tinted lead, then the standout player (or stacked avatars + "K players"
 * on a tie) and the headline delta. A "No movement yet" resting state keeps the card present — and
 * the layout balanced — before anyone has moved (e.g. until the first official results are approved).
 */
function MoverCard({
    title,
    explanation,
    icon: Icon,
    tone,
    toneClassName,
    result,
    format,
    emptyLabel,
    meId,
}: {
    title: string;
    explanation: string;
    icon: LucideIcon;
    tone: 'gold' | 'green' | 'muted' | 'destructive';
    toneClassName: string;
    result: { leaders: ProjectedRow[]; value: number } | null;
    format: (value: number) => string;
    emptyLabel?: string;
    meId: number;
}) {
    const { t, tChoice } = useTranslation();
    const [open, setOpen] = useState(false);

    const heading = (
        <span className="inline-flex min-w-0 items-center gap-1.5 pr-6 text-[0.7rem] font-bold tracking-[0.12em] text-muted-foreground uppercase">
            <Icon className={cn('size-3.5 shrink-0', toneClassName)} />
            {title}
        </span>
    );

    if (!result) {
        return (
            <div className="card-elevated flex flex-col gap-3 rounded-2xl border border-border bg-card p-4">
                {heading}
                <p className="text-sm text-muted-foreground">
                    {emptyLabel ?? t('No movement yet')}
                </p>
            </div>
        );
    }

    const playersLabel =
        result.leaders.length === 1
            ? result.leaders[0].user_id === meId
                ? t('You')
                : result.leaders[0].name
            : tChoice(':count player|:count players', result.leaders.length);

    const players: StatDetailPlayer[] = result.leaders.map((row) => ({
        id: row.entry_id,
        name: row.user_id === meId ? t('You') : row.name,
        initials: row.initials,
        avatar: row.avatar,
        isMe: row.user_id === meId,
    }));

    return (
        <>
            <button
                type="button"
                onClick={() => setOpen(true)}
                className="press-soft card-elevated group relative flex w-full flex-col gap-3 rounded-2xl border border-border bg-card p-4 text-left transition hover:border-accent/60"
            >
                {heading}
                <ChevronRight className="absolute top-3 right-3 size-4 text-muted-foreground/50 transition-colors group-hover:text-accent" />
                <div className="flex min-w-0 items-center gap-3">
                    <AvatarStack
                        size="sm"
                        players={result.leaders.map((row) => ({
                            id: row.entry_id,
                            name: row.name,
                            initials: row.initials,
                            avatar: row.avatar,
                            isMe: row.user_id === meId,
                        }))}
                    />
                    <div className="min-w-0 flex-1">
                        <p className="truncate font-display font-semibold">
                            {playersLabel}
                        </p>
                        <p
                            className={cn(
                                'truncate font-display text-sm font-semibold tabular-nums',
                                toneClassName,
                            )}
                        >
                            {format(result.value)}
                        </p>
                    </div>
                </div>
            </button>
            <StatDetailDialog
                open={open}
                onOpenChange={setOpen}
                icon={Icon}
                tone={tone}
                title={title}
                explanation={explanation}
                summary={`${playersLabel} · ${format(result.value)}`}
                players={players}
            />
        </>
    );
}

/**
 * The pool's live movers — who's earning the most/least from the live scores (Top earner / Quietest,
 * among players actually gaining) and who's climbing/falling the most (movement vs the banked official
 * rank). All four cards always render so the row beside the viewer card stays balanced; earner cards
 * rest until someone gains, climber/faller until the first official results are approved.
 */
function LiveMovers({
    rows,
    primaryLabel,
    meId,
    className,
}: {
    rows: ProjectedRow[];
    primaryLabel: string;
    meId: number;
    className?: string;
}) {
    const { t, tChoice } = useTranslation();
    const label = primaryLabel.toLowerCase();
    const gain = (row: ProjectedRow): number => row.live_gain;
    const isGainer = (row: ProjectedRow): boolean => row.live_gain > 0;
    const delta = (row: ProjectedRow): number => row.movement_delta ?? 0;

    const topEarner = pickLeaders(rows, gain, isGainer, 'high');
    const quietest = pickLeaders(rows, gain, isGainer, 'low');
    const climber = pickLeaders(
        rows,
        delta,
        (row) => row.movement === 'up',
        'high',
    );
    const faller = pickLeaders(
        rows,
        delta,
        (row) => row.movement === 'down',
        'high',
    );

    return (
        <div className={cn('grid grid-cols-2 gap-3', className)}>
            <MoverCard
                title={t('Top earner')}
                explanation={t(
                    'Gaining the most points from the matches in play right now.',
                )}
                icon={Crown}
                tone="gold"
                toneClassName="text-accent"
                result={topEarner}
                format={(value) => `+${value} ${label}`}
                emptyLabel={t('No earnings yet')}
                meId={meId}
            />
            <MoverCard
                title={t('Quietest')}
                explanation={t(
                    'Gaining the fewest points from the matches in play right now.',
                )}
                icon={TrendingDown}
                tone="muted"
                toneClassName="text-muted-foreground"
                result={quietest}
                format={(value) => `+${value} ${label}`}
                emptyLabel={t('No earnings yet')}
                meId={meId}
            />
            <MoverCard
                title={t('Biggest climber')}
                explanation={t(
                    'Climbing the most places against the official standings.',
                )}
                icon={ArrowUp}
                tone="green"
                toneClassName="text-primary"
                result={climber}
                format={(value) =>
                    `▲${value} ${tChoice('place|places', value)}`
                }
                meId={meId}
            />
            <MoverCard
                title={t('Biggest faller')}
                explanation={t(
                    'Falling the most places against the official standings.',
                )}
                icon={ArrowDown}
                tone="destructive"
                toneClassName="text-destructive"
                result={faller}
                format={(value) =>
                    `▼${value} ${tChoice('place|places', value)}`
                }
                meId={meId}
            />
        </div>
    );
}

function ProjectedBoard({
    pool,
    board,
    meId,
}: {
    pool: LivePool;
    board: LiveBoardDescriptor;
    meId: number;
}) {
    const { t } = useTranslation();
    const rows = pool.boards[board.key] ?? [];
    const mine = rows.find((row) => row.user_id === meId);

    const toEntry = (row: ProjectedRow): LeaderboardEntry => ({
        rank: row.rank,
        name: row.user_id === meId ? t('You') : row.name,
        initials: row.initials,
        avatar: row.avatar,
        primary: row.primary_value,
        secondary:
            board.secondary_stat_label && row.secondary_value != null
                ? `${row.secondary_value} ${board.secondary_stat_label}`
                : null,
        isMe: row.user_id === meId,
        movement: row.movement,
        movementDelta: row.movement_delta,
        liveGain: row.live_gain,
        prize:
            board.awards_prizes && pool.is_paid && row.projected_prize != null
                ? formatMoney(row.projected_prize, pool.currency)
                : null,
    });

    return (
        <div className="flex flex-col gap-4">
            <div className="flex flex-col gap-4 lg:flex-row lg:items-stretch">
                {mine && (
                    <ViewerProjection
                        row={mine}
                        pool={pool}
                        primaryLabel={board.primary_stat_label}
                        className="lg:w-80 lg:shrink-0"
                    />
                )}

                <LiveMovers
                    rows={rows}
                    primaryLabel={board.primary_stat_label}
                    meId={meId}
                    className="lg:flex-1"
                />
            </div>

            <p className="text-sm text-muted-foreground">{board.description}</p>

            {rows.length > 0 ? (
                <StandingsList
                    key={board.key}
                    entries={rows.map(toEntry)}
                    primaryStatLabel={board.primary_stat_label}
                    stickyOffsetClassName="bottom-3 sm:bottom-6"
                />
            ) : (
                <p className="rounded-2xl border border-dashed border-border p-8 text-center text-sm text-muted-foreground">
                    {t('No players to project yet.')}
                </p>
            )}
        </div>
    );
}

function PoolLiveSection({
    pool,
    tournamentName,
    boards,
    meId,
    pools,
    selectedSlug,
    onSelectPool,
}: {
    pool: LivePool;
    tournamentName: string;
    boards: LiveBoardDescriptor[];
    meId: number;
    pools: LivePool[];
    selectedSlug: string;
    onSelectPool: (slug: string) => void;
}) {
    const { t } = useTranslation();
    const [boardKey, setBoardKey] = useState(boards[0]?.key ?? 'overall');
    const board = boards.find((item) => item.key === boardKey) ?? boards[0];
    const canSwitch = pools.length > 1;

    const identity = (
        <PoolIdentity
            source={pool.source}
            name={pool.name}
            tournament={tournamentName}
            scoringLabel={pool.scoring_label}
            accent={pool.accent}
            variant="banner"
            className="min-w-0 flex-1"
        />
    );

    return (
        <section className="flex flex-col gap-4">
            <div className="flex flex-wrap items-center justify-between gap-3">
                {canSwitch ? (
                    <>
                        {/* Mobile: tap the pool header to switch which pool's live data shows. */}
                        <Sheet>
                            <SheetTrigger asChild>
                                <button
                                    type="button"
                                    aria-label={t('Switch pool')}
                                    className="press flex w-full items-center gap-2 rounded-2xl border border-border bg-card px-3 py-2.5 text-left shadow-[var(--sh-sm)] md:hidden"
                                >
                                    {identity}
                                    <ChevronDown className="size-5 shrink-0 text-muted-foreground" />
                                </button>
                            </SheetTrigger>
                            <SheetContent
                                side="bottom"
                                className="rounded-t-3xl px-4 pt-5 pb-[calc(1rem+env(safe-area-inset-bottom,0px))]"
                            >
                                <SheetHeader className="p-0">
                                    <SheetTitle className="font-display text-base">
                                        {t('Switch pool')}
                                    </SheetTitle>
                                    <SheetDescription className="sr-only">
                                        {t(
                                            "Choose which pool's live standings to follow.",
                                        )}
                                    </SheetDescription>
                                </SheetHeader>
                                <ul className="flex flex-col gap-1">
                                    {pools.map((entry) => {
                                        const kit = resolveAccent(entry.accent);
                                        const current =
                                            entry.slug === selectedSlug;

                                        return (
                                            <li key={entry.slug}>
                                                <SheetClose asChild>
                                                    <button
                                                        type="button"
                                                        onClick={() =>
                                                            onSelectPool(
                                                                entry.slug,
                                                            )
                                                        }
                                                        className={cn(
                                                            'press flex w-full items-center gap-3 rounded-2xl px-3 py-2.5 text-left transition-colors',
                                                            current
                                                                ? 'bg-secondary'
                                                                : 'hover:bg-muted',
                                                        )}
                                                    >
                                                        <span
                                                            className={cn(
                                                                'flex size-8 shrink-0 items-center justify-center rounded-lg font-display text-xs font-bold',
                                                                kit.railClass,
                                                                kit.textClass,
                                                            )}
                                                            aria-hidden
                                                        >
                                                            {sourceMonogram(
                                                                entry.source,
                                                            )}
                                                        </span>
                                                        <span className="min-w-0 flex-1 truncate font-display font-semibold">
                                                            {entry.name}
                                                        </span>
                                                        {current && (
                                                            <Check className="size-4 shrink-0 text-primary" />
                                                        )}
                                                    </button>
                                                </SheetClose>
                                            </li>
                                        );
                                    })}
                                </ul>
                            </SheetContent>
                        </Sheet>

                        {/* Desktop: plain identity; the pool tabs above handle switching. */}
                        <div className="hidden min-w-0 md:block">
                            {identity}
                        </div>
                    </>
                ) : (
                    identity
                )}
                <span className="font-display text-xs font-semibold text-muted-foreground">
                    {t('Projected · if scores hold')}
                </span>
            </div>

            <SegmentedTabs
                aria-label={t('Projected leaderboard')}
                items={boards.map((item) => ({
                    value: item.key,
                    label: item.label,
                }))}
                value={board.key}
                onChange={setBoardKey}
                size="sm"
            />

            <ProjectedBoard pool={pool} board={board} meId={meId} />
        </section>
    );
}

export default function LiveShow({
    tournament,
    boards,
    pools,
    liveFixtures,
    poll_interval_ms,
}: LiveShowProps) {
    const { t, tChoice } = useTranslation();
    const page = usePage();
    const meId = page.props.auth.user?.id ?? 0;
    const [poolSlug, setPoolSlug] = useState(pools[0]?.slug ?? '');
    const selectedPool =
        pools.find((pool) => pool.slug === poolSlug) ?? pools[0] ?? null;

    useLivePoll({
        intervalMs: poll_interval_ms,
        active: liveFixtures.length > 0,
        only: LIVE_ONLY,
    });

    const liveCount = liveFixtures.filter(
        (fixture) => fixture.status === 'live',
    ).length;

    return (
        <>
            <Head
                title={t(':tournament · Live', {
                    tournament: t(tournament.name),
                })}
            />
            <div className="relative min-h-full bg-background">
                <div className="w-full px-4 py-6 sm:px-6 sm:py-8 lg:px-8 xl:px-10">
                    <header className="hero relative mb-6 hidden overflow-hidden rounded-3xl border border-border p-5 sm:mb-8 sm:p-8 md:block">
                        <div className="hero-lines" />
                        <div className="relative flex flex-wrap items-end justify-between gap-4">
                            <div className="flex flex-col gap-3">
                                <span className="inline-flex w-fit items-center gap-2 text-xs font-bold tracking-[0.14em] text-muted-foreground uppercase">
                                    <LivePulse />
                                    {t('Live Center')}
                                </span>
                                <h1 className="text-3xl font-semibold tracking-tight text-balance text-foreground sm:text-5xl">
                                    {t(tournament.name)}
                                </h1>
                                <span className="bg-gold-gradient mt-1 h-1 w-12 rounded-full" />
                                <p className="text-sm font-semibold text-muted-foreground">
                                    {liveCount > 0
                                        ? tChoice(
                                              ':count match live now — watch your standings move in real time.|:count matches live now — watch your standings move in real time.',
                                              liveCount,
                                          )
                                        : t('No matches live at the moment.')}
                                </p>
                            </div>
                        </div>
                    </header>

                    {/* Mobile: a simple tournament title in place of the hidden hero. */}
                    <h1 className="mb-4 font-display text-2xl font-semibold tracking-tight text-foreground md:hidden">
                        {t(tournament.name)}
                    </h1>

                    <div className="flex flex-col gap-8">
                        <section className="flex flex-col gap-4">
                            <h2 className="flex items-center gap-2 font-display text-lg font-semibold">
                                {/* The hero's "live now" signal, kept beside this heading on mobile. */}
                                {liveCount > 0 && (
                                    <span className="md:hidden">
                                        <LivePulse />
                                    </span>
                                )}
                                {t('Live scores')}
                            </h2>
                            <LiveScoreboard
                                fixtures={liveFixtures}
                                pool={selectedPool}
                                meId={meId}
                                multiPool={pools.length > 1}
                            />
                        </section>

                        {/* Desktop only: the pool tabs. On mobile, the pool header below is tappable. */}
                        {pools.length > 1 && (
                            <SegmentedTabs
                                className="hidden md:flex"
                                aria-label={t('Your pools')}
                                items={pools.map((pool) => {
                                    const kit = resolveAccent(pool.accent);

                                    return {
                                        value: pool.slug,
                                        label: (
                                            <span className="inline-flex items-center gap-1.5">
                                                <span
                                                    className={cn(
                                                        'flex size-4 shrink-0 items-center justify-center rounded font-display text-[0.5rem] leading-none font-bold',
                                                        kit.railClass,
                                                        kit.textClass,
                                                    )}
                                                    aria-hidden
                                                >
                                                    {sourceMonogram(
                                                        pool.source,
                                                    )}
                                                </span>
                                                {pool.name}
                                            </span>
                                        ),
                                    };
                                })}
                                value={selectedPool?.slug ?? ''}
                                onChange={setPoolSlug}
                            />
                        )}

                        {selectedPool ? (
                            <PoolLiveSection
                                key={selectedPool.slug}
                                pool={selectedPool}
                                tournamentName={tournament.name}
                                boards={boards}
                                meId={meId}
                                pools={pools}
                                selectedSlug={poolSlug}
                                onSelectPool={setPoolSlug}
                            />
                        ) : (
                            <p className="text-sm text-muted-foreground">
                                {t(
                                    'Join a pool over this tournament to follow your live standings.',
                                )}
                            </p>
                        )}
                    </div>
                </div>
            </div>
        </>
    );
}

LiveShow.layout = {
    breadcrumbs: [{ title: 'Live', href: live.index() }],
};
