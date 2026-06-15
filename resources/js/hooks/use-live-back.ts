import { router, usePage } from '@inertiajs/react';
import { useCallback, useEffect } from 'react';
import { index as poolsIndex } from '@/routes/pools';

// Whether the user has visited a non-live page in this SPA session — i.e. there is an in-app
// entry behind the current Live page to go back to. Module-global so it survives the Live Center's
// own re-renders; resets on a full reload, where we deliberately prefer the pools-index fallback
// over risking a history.back() that bounces the user to login / off the app.
let hasInAppHistory = false;

/**
 * Drives the mobile top-nav's Live affordance. On the Live Center (URLs starting with `/live`) the
 * button becomes a Back control: `goBack()` uses the browser's native history — returning to the
 * exact previous page with scroll restored — whenever we know an in-app page sits behind us, and
 * otherwise (a direct deep-link / refresh onto a Live page with no in-app back-stack) falls back to
 * the pools index so Back can never dump the user on the login page or off the app entirely.
 */
export function useLiveBack(): { onLive: boolean; goBack: () => void } {
    const { url } = usePage();
    const onLive = url.startsWith('/live');

    useEffect(() => {
        if (!onLive) {
            hasInAppHistory = true;
        }
    }, [onLive]);

    const goBack = useCallback((): void => {
        if (hasInAppHistory) {
            window.history.back();

            return;
        }

        router.visit(poolsIndex.url());
    }, []);

    return { onLive, goBack };
}
