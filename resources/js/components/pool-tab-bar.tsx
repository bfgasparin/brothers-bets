import { Link, usePage } from '@inertiajs/react';
import { tournamentNavItems } from '@/components/nav-tournament';
import { useCurrentUrl } from '@/hooks/use-current-url';
import { useIsMobile } from '@/hooks/use-mobile';
import { useTranslation } from '@/hooks/use-translation';
import { cn } from '@/lib/utils';
import type { JoinedPool, TournamentNavInfo } from '@/types/navigation';

/**
 * Fixed bottom navigation for a pool's three primary screens (Overview / Predict / Leaderboards),
 * shown only on mobile. The hamburger drawer still owns switching pools and settings; this keeps a
 * pool's main screens one thumb-tap away instead of two. It renders nothing on desktop (the left
 * sidebar covers that) or outside a pool (no `pool` prop). A gold dot marks Predict when the pool
 * has unfinished picks, mirroring the sidebar's "needs attention" cue.
 *
 * The links deliberately do NOT `prefetch`: on touch the prefetch only starts at tap time (no hover
 * head start), so it just duplicates the GET and routes the visit through Inertia's prefetch path —
 * which never fires the `start` event the NavigationIndicator pill listens for.
 */
export function PoolTabBar() {
    const { props } = usePage<{
        pool?: TournamentNavInfo;
        joinedPools?: JoinedPool[];
    }>();
    const { isCurrentUrl } = useCurrentUrl();
    const isMobile = useIsMobile();
    const { t } = useTranslation();

    const pool = props.pool;

    if (!pool || !isMobile) {
        return null;
    }

    const items = tournamentNavItems(pool.slug);
    const needsAttention = (props.joinedPools ?? []).some(
        (joined) => joined.slug === pool.slug && joined.needs_attention,
    );

    return (
        <div
            className="pointer-events-none fixed inset-x-0 bottom-0 z-40 flex justify-center px-3 md:hidden"
            style={{
                paddingBottom:
                    'calc(0.75rem + env(safe-area-inset-bottom, 0px))',
            }}
        >
            <nav
                aria-label={t('Pool sections')}
                className="floating-nav-surface pointer-events-auto flex w-full max-w-md items-stretch gap-1 rounded-2xl p-1.5 shadow-[var(--sh-lg)]"
            >
                {items.map((item) => {
                    const active = isCurrentUrl(item.href);
                    const Icon = item.icon;

                    return (
                        <Link
                            key={item.title}
                            href={item.href}
                            aria-current={active ? 'page' : undefined}
                            className={cn(
                                'flex flex-1 flex-col items-center justify-center gap-0.5 rounded-xl py-1.5 text-[11px] font-semibold transition-colors',
                                active
                                    ? 'bg-primary/10 text-primary'
                                    : 'text-muted-foreground',
                            )}
                        >
                            <span className="relative">
                                <Icon className="size-5" />
                                {item.predict && needsAttention && (
                                    <span
                                        className="bg-gold-gradient absolute -top-0.5 -right-1.5 size-2 rounded-full shadow-[var(--sh-sm)]"
                                        aria-hidden
                                    />
                                )}
                            </span>
                            {t(item.title)}
                        </Link>
                    );
                })}
            </nav>
        </div>
    );
}
