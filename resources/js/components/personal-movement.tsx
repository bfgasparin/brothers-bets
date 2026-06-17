import { ArrowDown, ArrowUp, Minus } from 'lucide-react';
import { useTranslation } from '@/hooks/use-translation';
import type { RankMovement } from '@/types/pools';

/**
 * A white, translucent rank-movement indicator that stays legible on a branded gradient card (where
 * the standard {@see MovementArrow} green/red pills would clash). Renders nothing when the rank held,
 * and a neutral dash on a first appearance, where there's no prior board to move against.
 */
export function PersonalMovement({
    movement,
    delta,
}: {
    movement: RankMovement | null;
    delta: number | null;
}) {
    const { t } = useTranslation();

    if (movement === null || movement === 'same') {
        return null;
    }

    if (movement === 'new') {
        return <Minus className="size-3.5" aria-label={t('No change')} />;
    }

    const up = movement === 'up';
    const Icon = up ? ArrowUp : ArrowDown;

    return (
        <span className="inline-flex items-center gap-0.5 rounded-full bg-white/15 px-2 py-0.5 font-display text-xs font-semibold tabular-nums">
            <Icon className="size-3.5" />
            {delta}
        </span>
    );
}
