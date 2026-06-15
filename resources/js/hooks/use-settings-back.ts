import { router, usePage } from '@inertiajs/react';
import { useCallback, useEffect } from 'react';
import { index as poolsIndex } from '@/routes/pools';

// Did the user step into Settings from another in-app page (so the browser history holds that page to
// return to)? Module-global because the Settings shell only mounts once we're already inside Settings
// — the page BEFORE Settings is observed by `useSettingsBackTracker`, mounted in `MobileTopNav` next
// to `useLiveBack` (the always-present mobile nav). `lastUrl` is module-level too so it survives the
// layout reconciliation across the transition. A full reload re-initialises both, so a cold deep-link
// into Settings keeps the pools-index fallback rather than a history.back() that could leave the app.
// Mirrors the intent of `use-live-back.ts`, but keyed on entering the `/settings` area.
let enteredFromApp = false;
let lastUrl: string | null = null;

/**
 * Observes SPA navigation from an always-mounted host (`MobileTopNav`) and records whether the user
 * reached Settings from another in-app page. Call once where it runs on every visit — it must see the
 * page the user was on *before* Settings opened.
 */
export function useSettingsBackTracker(): void {
    const { url } = usePage();

    useEffect(() => {
        const inSettings = url.startsWith('/settings');
        const cameFromSettings = lastUrl?.startsWith('/settings') ?? false;

        if (inSettings) {
            // Flag "has a page behind it" only on the non-settings → settings transition; a direct
            // deep-link / reload onto Settings has `lastUrl === null` and stays in the fallback.
            if (lastUrl !== null && !cameFromSettings) {
                enteredFromApp = true;
            }
        } else {
            enteredFromApp = false;
        }

        lastUrl = url;
    }, [url]);
}

/**
 * The Settings shell's "leave Settings" control (used on the section list). Returns to the exact page
 * the user opened Settings from via native history — scroll restored — or, when Settings was opened
 * cold (a direct deep-link / reload), the pools index so Back can never dump the user off the app.
 */
export function useSettingsBack(): { goBack: () => void } {
    const goBack = useCallback((): void => {
        if (enteredFromApp) {
            window.history.back();

            return;
        }

        router.visit(poolsIndex.url());
    }, []);

    return { goBack };
}
