import { usePage } from '@inertiajs/react';
import { AppContent } from '@/components/app-content';
import { AppShell } from '@/components/app-shell';
import { AppSidebar } from '@/components/app-sidebar';
import { AppSidebarHeader } from '@/components/app-sidebar-header';
import { InstallBanner } from '@/components/install-banner';
import { ManageTabBar } from '@/components/manage-tab-bar';
import { MobileBrowserTopNav } from '@/components/mobile-browser-top-nav';
import { MobileTopNav } from '@/components/mobile-top-nav';
import { manageSlugFromUrl } from '@/components/nav-manage';
import { NavigationIndicator } from '@/components/navigation-indicator';
import { PoolTabBar } from '@/components/pool-tab-bar';
import { cn } from '@/lib/utils';
import type { AppLayoutProps } from '@/types';
import type { TournamentNavInfo } from '@/types/navigation';

export default function AppSidebarLayout({
    children,
    breadcrumbs = [],
}: AppLayoutProps) {
    const page = usePage<{ pool?: TournamentNavInfo }>();
    // In-pool pages share the `pool` prop; a manage-tournament page is detected from the URL. Only
    // then do we mount the matching mobile bottom tab bar and reserve the strip it occupies
    // (`--pool-tab-bar-h` is 0 off-mobile, so desktop is untouched; the two bars never co-occur).
    const inPool = Boolean(page.props.pool);
    const inManage = manageSlugFromUrl(page.url) !== null;

    return (
        <AppShell variant="sidebar">
            <AppSidebar />
            <AppContent
                variant="sidebar"
                className={cn(
                    'has-floating-nav overflow-x-hidden pt-[var(--top-nav-h)]',
                    inPool && 'has-pool-tab-bar pb-[var(--pool-tab-bar-h)]',
                    inManage && 'has-manage-tab-bar pb-[var(--pool-tab-bar-h)]',
                )}
            >
                <AppSidebarHeader breadcrumbs={breadcrumbs} />
                <MobileBrowserTopNav />
                {children}
                <MobileTopNav />
                <NavigationIndicator />
                <PoolTabBar />
                <ManageTabBar />
                <InstallBanner />
            </AppContent>
        </AppShell>
    );
}
