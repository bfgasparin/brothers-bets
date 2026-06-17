import { ArrowDown, ArrowUp, Minus } from 'lucide-react';
import { useTranslation } from '@/hooks/use-translation';
import { cn } from '@/lib/utils';
import type { RankMovement } from '@/types/pools';

const PILL_BASE =
    'inline-flex items-center gap-0.5 rounded-full font-display font-semibold tabular-nums leading-none shadow-[var(--sh-sm)]';

const SIZE = {
    sm: { pill: 'px-1.5 py-0.5 text-[11px]', icon: 'size-3' },
    md: { pill: 'px-2 py-0.5 text-xs', icon: 'size-3.5' },
} as const;

const UP_CLASSES = 'bg-brand-gradient text-white';
const DOWN_CLASSES =
    'bg-[linear-gradient(135deg,#ff7a7d_0%,#e23b40_100%)] text-white';

/**
 * How an entry moved on the pool table since the last approved results: a gradient pill with an
 * up/down arrow and the number of places moved, or a neutral dash for no change — including a first
 * appearance, where there's no prior board to move against — or nothing before any results have
 * landed. The visible label is the number alone; the full phrase ("Up 3 places") rides in the
 * accessible label.
 */
export function MovementArrow({
    movement,
    delta,
    size = 'sm',
    className,
}: {
    movement: RankMovement | null;
    /** Places moved; null/absent renders the pill without a number. */
    delta?: number | null;
    size?: 'sm' | 'md';
    className?: string;
}) {
    const { t } = useTranslation();

    if (movement === null) {
        return null;
    }

    if (movement === 'same' || movement === 'new') {
        return (
            <Minus
                className={cn(
                    SIZE[size].icon,
                    'text-muted-foreground/50',
                    className,
                )}
                aria-label={t('No change')}
            />
        );
    }

    const up = movement === 'up';
    const Icon = up ? ArrowUp : ArrowDown;
    const places = delta != null && delta > 0 ? delta : null;
    const label =
        places === null
            ? up
                ? t('Moved up')
                : t('Moved down')
            : up
              ? places === 1
                  ? t('Up 1 place')
                  : t('Up :count places', { count: places })
              : places === 1
                ? t('Down 1 place')
                : t('Down :count places', { count: places });

    return (
        <span
            className={cn(
                PILL_BASE,
                SIZE[size].pill,
                up ? UP_CLASSES : DOWN_CLASSES,
                className,
            )}
            role="img"
            aria-label={label}
        >
            <Icon className={cn(SIZE[size].icon, 'shrink-0')} aria-hidden />
            {places !== null && <span>{places}</span>}
        </span>
    );
}
