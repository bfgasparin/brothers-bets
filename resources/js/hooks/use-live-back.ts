import { usePage } from '@inertiajs/react';
import { useEffect, useSyncExternalStore } from 'react';
import { index as poolsIndex } from '@/routes/pools';

const STORAGE_KEY = 'bb:live-back';

const poolsHref = (): string => poolsIndex.url();

const listeners = new Set<() => void>();

const notify = (): void => listeners.forEach((listener) => listener());

const subscribe = (callback: () => void): (() => void) => {
    listeners.add(callback);

    return () => {
        listeners.delete(callback);
    };
};

const readStored = (): string => {
    if (typeof window === 'undefined') {
        return poolsHref();
    }

    try {
        return window.sessionStorage.getItem(STORAGE_KEY) ?? poolsHref();
    } catch {
        return poolsHref();
    }
};

// Cached snapshot — `useSyncExternalStore` requires a stable reference between changes. Seeded from
// sessionStorage so an in-session reload while on a Live page still knows the pool to return to.
let snapshot: string = readStored();

const getSnapshot = (): string => snapshot;

const remember = (href: string): void => {
    if (snapshot === href) {
        return;
    }

    snapshot = href;

    if (typeof window !== 'undefined') {
        try {
            window.sessionStorage.setItem(STORAGE_KEY, href);
        } catch {
            // sessionStorage unavailable (e.g. private mode) — the in-memory snapshot still serves.
        }
    }

    notify();
};

/**
 * Remembers the page a user was on before entering the Live Center, so the mobile top-nav can offer
 * a Back affordance there instead of a (dead-end) Live link. We record only NON-live pages, so the
 * internal `live.index → live.show` redirect never clobbers the target: a pool page is remembered
 * verbatim (the exact sub-page the user left), anything else falls back to the pools index.
 */
export function useLiveBack(): { onLive: boolean; backHref: string } {
    const { url } = usePage();
    const onLive = url.startsWith('/live');

    useEffect(() => {
        if (onLive) {
            return;
        }

        remember(url.startsWith('/pools') ? url : poolsHref());
    }, [url, onLive]);

    const backHref = useSyncExternalStore(subscribe, getSnapshot, poolsHref);

    return { onLive, backHref };
}
