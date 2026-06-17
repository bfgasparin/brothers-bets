import { Link } from '@inertiajs/react';
import AppLogoIcon from '@/components/app-logo-icon';
import { useTranslation } from '@/hooks/use-translation';
import { home } from '@/routes';
import type { AuthLayoutProps } from '@/types';

/** The pitch panel's talking points — the same brand voice as the welcome screen. */
const STATS = [
    { stat: 'Live', label: 'Match predictions' },
    { stat: 'Season', label: 'Long leaderboards' },
    { stat: 'Glory', label: 'Bragging rights' },
];

/**
 * The branded split-screen auth shell: an immersive pitch panel (field lines, stadium glows, the
 * tagline and a few stats) fills one half on large screens, the form fills the other — so the
 * sign-in page wears the same pitch-green + gold identity as the rest of the app and leaves no
 * dead space. On small screens the panel drops away and the form takes the stage with a compact
 * logo lockup.
 */
export default function AuthSplitLayout({
    children,
    title,
    description,
}: AuthLayoutProps) {
    // `title`/`description` arrive as English keys from each auth page's `.layout` config (evaluated
    // at module load, before React) — translate them here at render time.
    const { t } = useTranslation();

    return (
        <div className="grid min-h-svh lg:grid-cols-2">
            <div className="hero relative hidden flex-col justify-between overflow-hidden p-10 lg:flex xl:p-14">
                <div className="hero-lines" />
                {/* Stadium-light glows */}
                <div className="pointer-events-none absolute inset-0 -z-10">
                    <div className="absolute -top-24 -right-28 size-[34rem] rounded-full bg-primary/25 blur-[120px]" />
                    <div className="absolute -bottom-40 -left-32 size-[26rem] rounded-full bg-accent/15 blur-[120px]" />
                </div>

                <Link
                    href={home()}
                    className="relative z-10 flex w-fit items-center gap-3"
                >
                    <div className="app-icon size-10 rounded-2xl shadow-[var(--sh-sm)]">
                        <AppLogoIcon className="size-6 text-white" />
                    </div>
                    <span className="inline-flex items-baseline font-display text-lg font-semibold tracking-tight text-foreground">
                        Brothers
                        <span className="ml-2 text-[10px] font-bold tracking-[0.22em] text-amber uppercase">
                            Bets
                        </span>
                    </span>
                </Link>

                <div className="relative z-10 flex flex-col gap-6">
                    <span className="inline-flex w-fit items-center gap-2.5 rounded-full bg-muted px-4 py-1.5 text-xs font-bold tracking-[0.14em] text-muted-foreground uppercase">
                        <span className="bg-brand-gradient size-2 rounded-full" />
                        {t('Members Only · Invite Required')}
                    </span>
                    <h2 className="max-w-md font-display text-4xl font-semibold tracking-tight text-balance text-foreground xl:text-5xl">
                        <span className="text-primary">{t('You predict')}</span>
                        . {t('You climb. You take the')}{' '}
                        <span className="text-gold">{t('glory')}</span>.
                    </h2>
                    <p className="max-w-sm text-pretty text-muted-foreground">
                        {t(
                            'Predict the fixtures, climb the leaderboard, and settle the bragging rights — every match, every season.',
                        )}
                    </p>
                </div>

                <dl className="relative z-10 grid grid-cols-3 gap-3">
                    {STATS.map((item) => (
                        <div
                            key={item.label}
                            className="rounded-2xl border border-border bg-card/70 p-4 shadow-[var(--sh-sm)] backdrop-blur"
                        >
                            <dt className="font-display text-lg font-semibold text-primary">
                                {t(item.stat)}
                            </dt>
                            <dd className="mt-0.5 text-xs text-muted-foreground">
                                {t(item.label)}
                            </dd>
                        </div>
                    ))}
                </dl>
            </div>

            <div className="flex flex-col items-center justify-center bg-background px-6 py-12 sm:px-10">
                <div className="flex w-full max-w-sm flex-col gap-8">
                    <Link
                        href={home()}
                        className="flex flex-col items-center gap-3 lg:hidden"
                    >
                        <div className="app-icon size-12 rounded-2xl shadow-[var(--sh-md)]">
                            <AppLogoIcon className="size-7 text-white" />
                        </div>
                        <span className="font-display text-lg font-semibold tracking-tight">
                            Brothers<span className="text-amber"> Bets</span>
                        </span>
                    </Link>

                    <div className="flex flex-col gap-2">
                        <h1 className="font-display text-2xl font-semibold tracking-tight text-foreground">
                            {title && t(title)}
                        </h1>
                        {description && (
                            <p className="text-sm text-muted-foreground">
                                {t(description)}
                            </p>
                        )}
                    </div>

                    {children}
                </div>
            </div>
        </div>
    );
}
