import { GitCompare } from 'lucide-react';
import { useTranslation } from '@/hooks/use-translation';

/**
 * The mobile-only floating entry point into compare mode. It mirrors {@link CompareDock}'s geometry
 * so it sits in the band directly above the floating pool tab bar (clearing the safe area), pinned
 * bottom-right so it never overlaps the centered tab-bar pill. Hidden on desktop, where the banner's
 * "Compare players" button owns this. The page renders it only while compare mode is off, so it never
 * co-exists with the dock.
 */
export function CompareFab({ onClick }: { onClick: () => void }) {
    const { t } = useTranslation();

    return (
        <div className="pointer-events-none fixed inset-x-0 bottom-[var(--pool-tab-bar-h)] z-50 flex justify-end px-3 pb-1 md:hidden">
            <button
                type="button"
                onClick={onClick}
                className="press floating-nav-surface pointer-events-auto inline-flex items-center gap-2 rounded-full py-2.5 pr-4 pl-3.5 font-display text-sm font-semibold shadow-[var(--sh-lg)]"
            >
                <GitCompare className="size-4" />
                {t('Compare')}
            </button>
        </div>
    );
}
