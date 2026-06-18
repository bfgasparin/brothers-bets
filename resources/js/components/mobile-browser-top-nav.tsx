import { Link, usePage } from '@inertiajs/react';
import AppLogo from '@/components/app-logo';
import { SidebarTrigger } from '@/components/ui/sidebar';
import { index as poolsIndex } from '@/routes/pools';

/**
 * The plain website header shown only on a phone *browser* — a small screen that is NOT the
 * installed PWA. The installed app keeps the floating chrome ({@link MobileTopNav} /
 * {@link PoolTabBar}); a browser tab falls back to this conventional sticky bar so the site
 * reads as a normal responsive website and the installed app stays the "premium" experience.
 *
 * Gating is pure CSS — `standalone:hidden` (hidden inside the installed PWA) + `md:hidden`
 * (hidden on desktop, which keeps the sidebar). It is deliberately NOT gated in JS on the
 * standalone axis: the bar must server-render and appear on first paint with no hydration
 * flash (with SSR on, a JS standalone check reads false until hydration). The hamburger opens
 * the existing off-canvas sidebar `Sheet`, which already carries the full nav — pool switcher,
 * sections, settings and the user menu — so it covers everything the floating chrome offered.
 *
 * Like {@link MobileTopNav}, it steps aside in Settings, whose mobile view is a self-contained
 * full-screen shell with its own back/title bar (the `url` is SSR-known, so this guard stays
 * flash-free).
 */
export function MobileBrowserTopNav() {
    const { url } = usePage();

    if (url.startsWith('/settings')) {
        return null;
    }

    return (
        <header
            className="sticky top-0 z-40 flex items-center gap-2 border-b border-sidebar-border/50 bg-background/90 px-3 pb-2 backdrop-blur md:hidden standalone:hidden"
            style={{
                paddingTop: 'calc(0.5rem + env(safe-area-inset-top, 0px))',
            }}
        >
            <SidebarTrigger className="size-9" />
            <Link href={poolsIndex()} className="flex min-w-0 items-center">
                <AppLogo />
            </Link>
        </header>
    );
}
