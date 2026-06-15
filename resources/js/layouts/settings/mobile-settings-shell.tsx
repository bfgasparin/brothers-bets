import { router, usePage } from '@inertiajs/react';
import {
    ArrowLeft,
    ChevronRight,
    Languages,
    Palette,
    ShieldCheck,
    User,
} from 'lucide-react';
import type { LucideIcon } from 'lucide-react';
import { useState } from 'react';
import type { PropsWithChildren, ReactNode } from 'react';
import { UserMenuButton } from '@/components/user-menu-button';
import { useCurrentUrl } from '@/hooks/use-current-url';
import { useSettingsBack } from '@/hooks/use-settings-back';
import { useTranslation } from '@/hooks/use-translation';
import { cn, toUrl } from '@/lib/utils';
import { edit as editAppearance } from '@/routes/appearance';
import { edit as editLanguage } from '@/routes/language';
import { edit as editProfile } from '@/routes/profile';
import { edit as editSecurity } from '@/routes/security';
import type { NavItem } from '@/types';
import type { Auth } from '@/types/auth';

type SettingsSection = {
    key: string;
    href: NavItem['href'];
    icon: LucideIcon;
    title: string;
    description: string;
};

const sections: SettingsSection[] = [
    {
        key: 'profile',
        href: editProfile(),
        icon: User,
        title: 'Profile',
        description: 'Your name, email and photo',
    },
    {
        key: 'security',
        href: editSecurity(),
        icon: ShieldCheck,
        title: 'Security',
        description: 'Passkeys and sign-in',
    },
    {
        key: 'language',
        href: editLanguage(),
        icon: Languages,
        title: 'Language',
        description: 'App language',
    },
    {
        key: 'appearance',
        href: editAppearance(),
        icon: Palette,
        title: 'Appearance',
        description: 'Theme and install',
    },
];

/**
 * Mobile-only Settings: a full-screen overlay that reads as an isolated account area (the floating
 * pool/live chrome steps aside — see `mobile-top-nav.tsx`). It opens on a big sub-section list, then
 * slides left into the chosen sub-page (master → detail). Its own flat top bar carries one Back
 * control: detail → list, or — on the list — out of Settings via the browser history.
 *
 * The shell lives in the persistent `SettingsLayout`, so its `view` state survives the Inertia
 * navigations between sub-pages — `{children}` swaps to the chosen section while the shell stays put.
 */
export default function MobileSettingsShell({ children }: PropsWithChildren) {
    const { t } = useTranslation();
    const { isCurrentUrl } = useCurrentUrl();
    const { goBack } = useSettingsBack();
    const { auth } = usePage<{ auth: Auth }>().props;
    const user = auth.user;

    const [view, setView] = useState<'list' | 'detail'>('list');
    const [loading, setLoading] = useState(false);
    const [activeKey, setActiveKey] = useState<string>(
        () =>
            sections.find((section) => isCurrentUrl(section.href))?.key ??
            'profile',
    );

    const activeSection =
        sections.find((section) => section.key === activeKey) ?? sections[0];

    const openSection = (section: SettingsSection): void => {
        setActiveKey(section.key);
        setView('detail');

        // Already on this sub-page — just slide; no navigation (and no skeleton flash).
        if (isCurrentUrl(section.href)) {
            return;
        }

        setLoading(true);
        router.visit(toUrl(section.href), {
            replace: true,
            preserveScroll: true,
            onFinish: () => setLoading(false),
        });
    };

    const handleBack = (): void => {
        if (view === 'detail') {
            setView('list');

            return;
        }

        goBack();
    };

    return (
        <div className="fixed inset-0 z-40 flex flex-col bg-background md:hidden">
            {/* Flat, grounded top bar — deliberately not the floating pill chrome of the app shell. */}
            <header
                className="flex shrink-0 items-center gap-2 border-b border-border/60 px-2 pb-2"
                style={{
                    paddingTop: 'calc(0.5rem + env(safe-area-inset-top, 0px))',
                }}
            >
                <button
                    type="button"
                    onClick={handleBack}
                    aria-label={t('Back')}
                    className="press flex size-9 shrink-0 items-center justify-center rounded-full text-muted-foreground transition-colors hover:bg-muted hover:text-foreground active:bg-muted"
                >
                    <ArrowLeft className="size-5" />
                </button>
                <span className="min-w-0 flex-1 truncate text-center font-display text-base font-semibold">
                    {view === 'detail' ? t(activeSection.title) : ''}
                </span>
                <UserMenuButton user={user} />
            </header>

            <div className="relative min-h-0 flex-1 overflow-hidden">
                <div
                    className={cn(
                        'flex h-full w-[200%] transition-transform duration-300 ease-out [will-change:transform]',
                        view === 'detail' && '-translate-x-1/2',
                    )}
                >
                    {/* Panel A — the section list. */}
                    <div className="h-full w-1/2 shrink-0 overflow-y-auto overscroll-contain px-4 pb-[calc(2rem+env(safe-area-inset-bottom,0px))]">
                        <div className="pt-3 pb-5">
                            <h1 className="font-display text-3xl font-bold tracking-tight">
                                {t('Settings')}
                            </h1>
                            <p className="mt-1 text-sm text-muted-foreground">
                                {t('Manage your profile and account settings')}
                            </p>
                        </div>

                        <ul className="flex flex-col gap-3">
                            {sections.map((section, index) => (
                                <li
                                    key={section.key}
                                    className="motion-safe:animate-in motion-safe:fade-in-0 motion-safe:slide-in-from-bottom-2"
                                    style={{
                                        animationDelay: `${index * 60}ms`,
                                        animationFillMode: 'both',
                                    }}
                                >
                                    <button
                                        type="button"
                                        onClick={() => openSection(section)}
                                        className="press group flex w-full items-center gap-4 rounded-2xl border border-border/60 bg-card p-4 text-left transition-colors hover:bg-muted active:bg-muted"
                                    >
                                        <span className="flex size-12 shrink-0 items-center justify-center rounded-xl bg-primary/10 text-primary">
                                            <section.icon className="size-6" />
                                        </span>
                                        <span className="min-w-0 flex-1">
                                            <span className="block font-display text-base font-semibold text-foreground">
                                                {t(section.title)}
                                            </span>
                                            <span className="block truncate text-sm text-muted-foreground">
                                                {t(section.description)}
                                            </span>
                                        </span>
                                        <ChevronRight className="size-5 shrink-0 text-muted-foreground transition-transform group-active:translate-x-0.5" />
                                    </button>
                                </li>
                            ))}
                        </ul>
                    </div>

                    {/* Panel B — the active sub-page. */}
                    <div className="h-full w-1/2 shrink-0 overflow-y-auto overscroll-contain px-4 pt-5 pb-[calc(2rem+env(safe-area-inset-bottom,0px))]">
                        {loading ? <DetailSkeleton /> : children}
                    </div>
                </div>
            </div>
        </div>
    );
}

function DetailSkeleton(): ReactNode {
    return (
        <div className="space-y-6" aria-hidden="true">
            <div className="space-y-2">
                <div className="h-5 w-32 animate-pulse rounded bg-muted" />
                <div className="h-4 w-56 animate-pulse rounded bg-muted" />
            </div>
            <div className="h-11 w-full animate-pulse rounded-xl bg-muted" />
            <div className="h-11 w-full animate-pulse rounded-xl bg-muted" />
            <div className="h-11 w-32 animate-pulse rounded-xl bg-muted" />
        </div>
    );
}
