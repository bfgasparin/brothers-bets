import { Link, usePage } from '@inertiajs/react';
import { ArrowLeft, Check, ChevronDown, LayoutGrid, Radio } from 'lucide-react';
import { useState } from 'react';
import { LivePulse } from '@/components/live-badge';
import { tournamentNavItems } from '@/components/nav-tournament';
import { Avatar, AvatarFallback, AvatarImage } from '@/components/ui/avatar';
import {
    DropdownMenu,
    DropdownMenuContent,
    DropdownMenuTrigger,
} from '@/components/ui/dropdown-menu';
import {
    Sheet,
    SheetClose,
    SheetContent,
    SheetDescription,
    SheetHeader,
    SheetTitle,
    SheetTrigger,
} from '@/components/ui/sheet';
import { UserMenuContent } from '@/components/user-menu-content';
import { useCurrentUrl } from '@/hooks/use-current-url';
import { useInitials } from '@/hooks/use-initials';
import { useLiveBack } from '@/hooks/use-live-back';
import { useIsMobile } from '@/hooks/use-mobile';
import { useTranslation } from '@/hooks/use-translation';
import { resolveAccent, sourceMonogram } from '@/lib/accents';
import { cn } from '@/lib/utils';
import live from '@/routes/live';
import { index as poolsIndex } from '@/routes/pools';
import type { User } from '@/types';
import type { Auth } from '@/types/auth';
import type { JoinedPool, TournamentNavInfo } from '@/types/navigation';

/**
 * The mobile top chrome that replaces the off-canvas sidebar: two detached floating buttons — a pool
 * switcher (left, opens a bottom sheet) and the user menu (right, a round avatar → dropdown). Hidden
 * on desktop, where the sidebar still owns this. Switching pool keeps the current section.
 * Links here deliberately do NOT `prefetch`: on touch that only duplicates the GET and suppresses
 * the NavigationIndicator pill (prefetch-served visits never fire `start`).
 */
export function MobileTopNav() {
    const isMobile = useIsMobile();
    const { props } = usePage<{
        pool?: TournamentNavInfo;
        joinedPools: JoinedPool[];
        auth: Auth;
        hasLiveMatches?: boolean;
    }>();
    const { onLive, backHref } = useLiveBack();

    if (!isMobile) {
        return null;
    }

    return (
        <div
            className="floating-top-bar pointer-events-auto fixed inset-x-0 top-0 z-40 flex items-center justify-between gap-2 px-3 pb-2 shadow-[var(--sh-md)] md:hidden"
            style={{
                paddingTop: 'calc(0.5rem + env(safe-area-inset-top, 0px))',
            }}
        >
            <LiveButton
                hasLive={Boolean(props.hasLiveMatches)}
                onLive={onLive}
                backHref={backHref}
            />
            <PoolSwitcher pool={props.pool} pools={props.joinedPools ?? []} />
            <UserMenuButton user={props.auth.user} />
        </div>
    );
}

/**
 * The floating live affordance. On the Live Center itself it becomes a Back button (the Live link
 * would be a dead end there) returning the user to the pool page they came from; everywhere else it
 * taps through to the Live Center, pulsing red while a match is live and sitting neutral otherwise.
 */
function LiveButton({
    hasLive,
    onLive,
    backHref,
}: {
    hasLive: boolean;
    onLive: boolean;
    backHref: string;
}) {
    const { t } = useTranslation();

    if (onLive) {
        return (
            <Link
                href={backHref}
                aria-label={t('Back')}
                className="press pointer-events-auto flex size-9 items-center justify-center rounded-full bg-secondary transition-colors"
            >
                <ArrowLeft className="size-4 text-muted-foreground" />
            </Link>
        );
    }

    return (
        <Link
            href={live.index()}
            aria-label={t('Live Center')}
            className={cn(
                'press pointer-events-auto flex size-9 items-center justify-center rounded-full transition-colors',
                hasLive ? 'bg-red-500/12' : 'bg-secondary',
            )}
        >
            {hasLive ? (
                <LivePulse />
            ) : (
                <Radio className="size-4 text-muted-foreground" />
            )}
        </Link>
    );
}

function PoolSwitcher({
    pool,
    pools,
}: {
    pool?: TournamentNavInfo;
    pools: JoinedPool[];
}) {
    const [open, setOpen] = useState(false);
    const { isCurrentUrl } = useCurrentUrl();
    const { t } = useTranslation();

    // Which in-pool section we're on, so a switch can land on the same section for the new pool.
    const currentItems = pool ? tournamentNavItems(pool.slug) : null;
    const activeIndex = currentItems
        ? currentItems.findIndex((item) => isCurrentUrl(item.href))
        : -1;
    const hrefFor = (slug: string): string =>
        tournamentNavItems(slug)[activeIndex >= 0 ? activeIndex : 0].href;

    const anyAttention = pools.some((entry) => entry.needs_attention);
    const accent = pool ? resolveAccent(pool.accent) : null;

    return (
        <Sheet open={open} onOpenChange={setOpen}>
            <SheetTrigger asChild>
                <button
                    type="button"
                    className="press pointer-events-auto inline-flex max-w-[52vw] items-center gap-2 rounded-full py-1.5 pr-3 pl-1.5"
                >
                    {pool && accent ? (
                        <span
                            className={cn(
                                'flex size-7 shrink-0 items-center justify-center rounded-full font-display text-[0.65rem] leading-none font-bold',
                                accent.railClass,
                                accent.textClass,
                            )}
                        >
                            {sourceMonogram(pool.source)}
                        </span>
                    ) : (
                        <span className="flex size-7 shrink-0 items-center justify-center rounded-full bg-secondary text-muted-foreground">
                            <LayoutGrid className="size-4" />
                        </span>
                    )}
                    <span className="truncate font-display text-sm font-semibold">
                        {pool ? pool.name : t('Pools')}
                    </span>
                    <ChevronDown className="size-4 shrink-0 text-muted-foreground" />
                    {anyAttention && (
                        <span
                            className="bg-gold-gradient size-2 shrink-0 rounded-full shadow-[var(--sh-sm)]"
                            aria-hidden
                        />
                    )}
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
                        {t('Switch between your pools or browse all pools.')}
                    </SheetDescription>
                </SheetHeader>
                <ul className="flex flex-col gap-1">
                    {pools.map((entry) => {
                        const entryAccent = resolveAccent(entry.accent);
                        const current = entry.slug === pool?.slug;

                        return (
                            <li key={entry.slug}>
                                <SheetClose asChild>
                                    <Link
                                        href={hrefFor(entry.slug)}
                                        className={cn(
                                            'press flex items-center gap-3 rounded-2xl px-3 py-2.5 transition-colors',
                                            current
                                                ? 'bg-secondary'
                                                : 'hover:bg-muted',
                                        )}
                                    >
                                        <span
                                            className={cn(
                                                'flex size-8 shrink-0 items-center justify-center rounded-lg font-display text-xs font-bold',
                                                entryAccent.railClass,
                                                entryAccent.textClass,
                                            )}
                                        >
                                            {sourceMonogram(entry.source)}
                                        </span>
                                        <span className="min-w-0 flex-1 truncate font-display font-semibold">
                                            {entry.name}
                                        </span>
                                        {entry.needs_attention && (
                                            <span
                                                className="bg-gold-gradient size-2 shrink-0 rounded-full"
                                                aria-hidden
                                            />
                                        )}
                                        {current && (
                                            <Check className="size-4 shrink-0 text-primary" />
                                        )}
                                    </Link>
                                </SheetClose>
                            </li>
                        );
                    })}
                    <li className="mt-1 border-t border-border pt-1">
                        <SheetClose asChild>
                            <Link
                                href={poolsIndex()}
                                className="press flex items-center gap-3 rounded-2xl px-3 py-2.5 transition-colors hover:bg-muted"
                            >
                                <span className="flex size-8 shrink-0 items-center justify-center rounded-lg bg-secondary text-muted-foreground">
                                    <LayoutGrid className="size-4" />
                                </span>
                                <span className="font-display font-semibold">
                                    {t('All pools')}
                                </span>
                            </Link>
                        </SheetClose>
                    </li>
                </ul>
            </SheetContent>
        </Sheet>
    );
}

function UserMenuButton({ user }: { user: User }) {
    const getInitials = useInitials();
    const { t } = useTranslation();

    return (
        <DropdownMenu modal={false}>
            <DropdownMenuTrigger asChild>
                <button
                    type="button"
                    aria-label={t('Account menu')}
                    className="press pointer-events-auto rounded-full p-0.5"
                >
                    <Avatar className="size-9 overflow-hidden rounded-full">
                        <AvatarImage src={user.avatar} alt={user.name} />
                        <AvatarFallback className="rounded-full bg-neutral-200 text-black dark:bg-neutral-700 dark:text-white">
                            {getInitials(user.name)}
                        </AvatarFallback>
                    </Avatar>
                </button>
            </DropdownMenuTrigger>
            <DropdownMenuContent
                align="end"
                side="bottom"
                className="min-w-56 rounded-lg [&_[data-slot=dropdown-menu-item]]:py-3"
            >
                <UserMenuContent user={user} />
            </DropdownMenuContent>
        </DropdownMenu>
    );
}
