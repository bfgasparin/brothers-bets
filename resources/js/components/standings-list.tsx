import { Loader2, LocateFixed } from 'lucide-react';
import { useCallback, useEffect, useRef, useState } from 'react';
import { flushSync } from 'react-dom';
import { LeaderboardRow } from '@/components/leaderboard-row';
import type { LeaderboardEntry } from '@/components/leaderboard-row';
import { MovementArrow } from '@/components/movement-arrow';
import { Button } from '@/components/ui/button';
import { useTranslation } from '@/hooks/use-translation';
import { cn } from '@/lib/utils';

const INITIAL_ROWS = 25;
const ROW_STEP = 25;

// Treat the viewer's row as off-screen until a little of it scrolls in, so the floating "Jump to me"
// card keeps offering a shortcut while the row is essentially out of sight. Kept well under a row's
// height (~60px) so even the LAST row — which can only reach the viewport's bottom edge — clears this
// line and lets the card hide, instead of being pinned behind it.
const ME_OBSERVER_BOTTOM_TRIM = 40; // px trimmed off the observer root's bottom edge

function formatValue(value: number | null): string {
    return value === null ? '—' : value.toLocaleString();
}

/**
 * A ranked standings table sized for hundreds of players: it renders a slice of the rows and reveals
 * more as the viewer scrolls (an IntersectionObserver sentinel), so the DOM never holds the whole
 * list at once. A sticky bar keeps the viewer's own position in view whenever their row is off-screen,
 * with a "Jump to me" action that scrolls straight to it.
 *
 * Callers map their domain rows to {@see LeaderboardEntry} (marking exactly one with `isMe`) and pass
 * the board's primary stat label for the sticky bar. Remount this (via a `key` on the active board)
 * so the revealed count resets when the viewer switches board.
 */
export function StandingsList({
    entries,
    primaryStatLabel,
    stickyOffsetClassName = 'bottom-[calc(max(0px,var(--pool-tab-bar-h)_-_0.5rem))]',
}: {
    entries: LeaderboardEntry[];
    primaryStatLabel: string;
    /**
     * Where the floating "Jump to me" bar sits — defaults to clearing the mobile pool tab bar,
     * tucked 0.5rem closer to the lowered tab pill. `max(0px, …)` clamps to `bottom-0` on desktop,
     * where `--pool-tab-bar-h` is `0` (no tab bar), so desktop positioning is unchanged.
     */
    stickyOffsetClassName?: string;
}) {
    const { t } = useTranslation();
    const [visibleCount, setVisibleCount] = useState(INITIAL_ROWS);
    const [meVisible, setMeVisible] = useState(false);

    const meElementRef = useRef<HTMLDivElement | null>(null);
    const meObserverRef = useRef<IntersectionObserver | null>(null);

    const myIndex = entries.findIndex((entry) => entry.isMe);
    const myEntry = myIndex >= 0 ? entries[myIndex] : null;
    const hasMore = visibleCount < entries.length;

    // Callback ref on the viewer's own row: (re)attach an observer that tracks whether it is on
    // screen, so the sticky "your position" bar shows only while the row is scrolled away.
    const attachMeRow = useCallback((element: HTMLDivElement | null) => {
        meObserverRef.current?.disconnect();
        meElementRef.current = element;

        if (element === null) {
            setMeVisible(false);

            return;
        }

        const observer = new IntersectionObserver(
            ([entry]) => setMeVisible(entry.isIntersecting),
            // Trim the bottom so a row only just peeking in still counts as off-screen (see
            // ME_OBSERVER_BOTTOM_TRIM) — small enough that even the last row clears it and hides the card.
            { rootMargin: `0px 0px -${ME_OBSERVER_BOTTOM_TRIM}px 0px` },
        );

        observer.observe(element);
        meObserverRef.current = observer;
    }, []);

    // Reveal another page whenever the sentinel scrolls into view.
    const attachSentinel = useCallback(
        (element: HTMLDivElement | null) => {
            if (element === null) {
                return;
            }

            const observer = new IntersectionObserver(
                ([entry]) => {
                    if (entry.isIntersecting) {
                        setVisibleCount((count) =>
                            Math.min(count + ROW_STEP, entries.length),
                        );
                    }
                },
                { rootMargin: '300px 0px' },
            );

            observer.observe(element);

            return () => observer.disconnect();
        },
        [entries.length],
    );

    useEffect(() => () => meObserverRef.current?.disconnect(), []);

    const jumpToMe = () => {
        if (myIndex < 0) {
            return;
        }

        // Reveal the viewer's row first (synchronously) if it is past the rendered slice, so it is
        // in the DOM to scroll to.
        if (myIndex >= visibleCount) {
            flushSync(() => setVisibleCount(myIndex + 1));
        }

        meElementRef.current?.scrollIntoView({
            behavior: 'smooth',
            block: 'center',
        });
    };

    return (
        <>
            <div className="overflow-hidden rounded-3xl border border-border bg-card shadow-[var(--sh-sm)]">
                {entries.slice(0, visibleCount).map((entry) => (
                    <LeaderboardRow
                        key={entry.rank}
                        rootRef={entry.isMe ? attachMeRow : undefined}
                        entry={entry}
                    />
                ))}

                {hasMore && (
                    <div
                        ref={attachSentinel}
                        className="flex items-center justify-center gap-2 border-t border-border py-4 text-xs font-medium text-muted-foreground"
                    >
                        <Loader2 className="size-4 animate-spin" />
                        {t('Loading more — :shown of :total', {
                            shown: visibleCount,
                            total: entries.length,
                        })}
                    </div>
                )}
            </div>

            {myEntry && !meVisible && (
                <div
                    className={cn(
                        'pointer-events-none fixed inset-x-0 z-40 flex justify-center px-3 pb-3 sm:pb-6',
                        stickyOffsetClassName,
                    )}
                >
                    <div className="floating-nav-surface pointer-events-auto flex w-full max-w-md items-center gap-3 rounded-2xl px-4 py-2.5 shadow-[var(--sh-lg)]">
                        <span className="grid size-9 shrink-0 place-items-center rounded-full bg-pitch-deep font-display text-sm font-semibold text-white tabular-nums">
                            {myEntry.rank}
                        </span>
                        <div className="min-w-0 flex-1">
                            <p className="font-display text-sm font-semibold">
                                {t('Your position')}
                            </p>
                            <p className="truncate text-xs text-muted-foreground">
                                {formatValue(myEntry.primary)}{' '}
                                {primaryStatLabel.toLowerCase()}
                            </p>
                        </div>
                        {myEntry.movement != null && (
                            <MovementArrow
                                movement={myEntry.movement}
                                delta={myEntry.movementDelta}
                                size="md"
                            />
                        )}
                        <Button
                            type="button"
                            size="sm"
                            variant="solid"
                            onClick={jumpToMe}
                            className="shrink-0"
                        >
                            <LocateFixed className="size-4" />
                            {t('Jump to me')}
                        </Button>
                    </div>
                </div>
            )}
        </>
    );
}
