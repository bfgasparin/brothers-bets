import { router } from '@inertiajs/react';
import { useEffect, useState } from 'react';
import { Spinner } from '@/components/ui/spinner';
import { useTranslation } from '@/hooks/use-translation';

// Wait this long before showing, so instant/cached visits finish first and never flash the pill.
const SHOW_DELAY_MS = 220;

/**
 * Mobile-only navigation feedback. Inertia's default top progress bar is a ~2px gray line that's
 * nearly invisible on phones — low contrast, and occluded by the iOS status bar under
 * `viewport-fit=cover` — so tapping into a new page gives no signal the request was received. This
 * drops a small glassy "Loading…" pill just below the floating top nav while a page navigation is
 * in flight. We only show it for foreground GET page visits: prefetch and silent/background reloads
 * (live polling, deferred/merged props) carry `showProgress: false`, and mutations (POST/PUT — e.g.
 * the prediction wizard's auto-save) own their own inline feedback, so neither flashes the pill.
 * Desktop is untouched (hidden via `md:hidden`) and keeps the default bar.
 *
 * The `finish` handler applies the same foreground-GET filter: every request (prefetches, polls,
 * the auto-save PUT) fires `finish`, and an unrelated background finish landing inside the 220ms
 * window would otherwise cancel the pill of a still-in-flight navigation. `visit.prefetch` can't be
 * used here — Inertia clears that flag before `finish` fires — but prefetch/background traffic is
 * reliably marked by `showProgress: false`. `navigate` is the fallback hide for visits served from
 * a prefetch cache, which swap the page without ever firing a qualifying `start`/`finish` pair.
 */
export function NavigationIndicator() {
    const { t } = useTranslation();
    const [visible, setVisible] = useState(false);

    useEffect(() => {
        let timer: ReturnType<typeof setTimeout> | undefined;

        const clearTimer = () => {
            if (timer) {
                clearTimeout(timer);
                timer = undefined;
            }
        };

        const stopStart = router.on('start', (event) => {
            const { visit } = event.detail;

            if (
                visit.prefetch ||
                !visit.showProgress ||
                visit.method !== 'get'
            ) {
                return;
            }

            clearTimer();
            timer = setTimeout(() => setVisible(true), SHOW_DELAY_MS);
        });

        const stopFinish = router.on('finish', (event) => {
            const { visit } = event.detail;

            if (!visit.showProgress || visit.method !== 'get') {
                return;
            }

            clearTimer();
            setVisible(false);
        });

        const stopNavigate = router.on('navigate', () => {
            clearTimer();
            setVisible(false);
        });

        return () => {
            clearTimer();
            stopStart();
            stopFinish();
            stopNavigate();
        };
    }, []);

    if (!visible) {
        return null;
    }

    return (
        <div
            role="status"
            aria-live="polite"
            className="pointer-events-none fixed inset-x-0 top-[var(--top-nav-h)] z-50 flex justify-center px-3 md:hidden"
        >
            <div className="floating-nav-surface mt-3 inline-flex items-center gap-2 rounded-full px-3.5 py-2 text-sm font-medium shadow-[var(--sh-lg)] motion-safe:animate-in motion-safe:fade-in motion-safe:slide-in-from-top-2">
                <Spinner className="size-4 text-primary" />
                {t('Loading…')}
            </div>
        </div>
    );
}
