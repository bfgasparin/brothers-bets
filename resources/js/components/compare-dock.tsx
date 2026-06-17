import { Pencil, Plus, X } from 'lucide-react';
import { Button } from '@/components/ui/button';
import { useTranslation } from '@/hooks/use-translation';
import { lane } from '@/lib/compare';
import { cn } from '@/lib/utils';
import type { PlayerDirectoryEntry } from '@/types/pools';

type SelectingProps = {
    mode: 'selecting';
    directory: PlayerDirectoryEntry[];
    selectedIds: number[];
    limit: number;
    onRemove: (entryId: number) => void;
    onOpenPicker: () => void;
    onCancel: () => void;
    onConfirm: () => void;
};

type ComparingProps = {
    mode: 'comparing';
    /** Total lanes including the viewer. */
    playerCount: number;
    canAddMore: boolean;
    onAddPlayer: () => void;
    onEdit: () => void;
    onExit: () => void;
};

/**
 * The always-visible floating control dock for compare mode. It floats at the bottom — centered on
 * mobile (where the sidebar is off-canvas) and bottom-right on larger screens (clear of the left
 * sidebar) — so confirming a selection and leaving compare are one click from anywhere on the long
 * page, neither buried at the foot nor only at the top. It renders the selection tray while choosing
 * players, and the exit/edit controls while comparing.
 */
export function CompareDock(props: SelectingProps | ComparingProps) {
    return (
        <div className="pointer-events-none fixed inset-x-0 bottom-[var(--pool-tab-bar-h)] z-50 flex justify-center px-3 pb-1 sm:justify-end sm:px-6 sm:pb-6">
            <div className="floating-nav-surface pointer-events-auto flex max-w-[calc(100vw-1.5rem)] flex-wrap items-center gap-2 rounded-2xl px-3 py-2.5 shadow-[var(--sh-lg)] sm:max-w-[36rem]">
                {props.mode === 'selecting' ? (
                    <SelectingDock {...props} />
                ) : (
                    <ComparingDock {...props} />
                )}
            </div>
        </div>
    );
}

function SelectingDock({
    directory,
    selectedIds,
    limit,
    onRemove,
    onOpenPicker,
    onCancel,
    onConfirm,
}: SelectingProps) {
    const { t } = useTranslation();
    const byId = new Map(directory.map((player) => [player.entry_id, player]));
    const atLimit = selectedIds.length >= limit;

    return (
        <>
            <span className="font-display text-sm font-semibold">
                {t('Comparing')}
            </span>

            <span
                className={cn(
                    'inline-flex items-center gap-1.5 rounded-full px-3 py-1 font-display text-xs font-semibold text-white',
                    lane(0).avatar,
                )}
            >
                {t('You')}
            </span>

            {selectedIds.map((id, index) => {
                const player = byId.get(id);
                const kit = lane(index + 1);

                return (
                    <span
                        key={id}
                        className="inline-flex items-center gap-1.5 rounded-full border border-border bg-secondary py-1 pr-1 pl-3 text-xs font-semibold"
                    >
                        <span
                            className={cn('size-2 rounded-full', kit.dot)}
                            aria-hidden
                        />
                        <span className="max-w-[7rem] truncate">
                            {player?.name ?? t('Player')}
                        </span>
                        <button
                            type="button"
                            onClick={() => onRemove(id)}
                            aria-label={t('Remove :name', {
                                name: player?.name ?? t('player'),
                            })}
                            className="press grid size-5 cursor-pointer place-items-center rounded-full text-muted-foreground transition-colors hover:bg-muted hover:text-foreground"
                        >
                            <X className="size-3.5" />
                        </button>
                    </span>
                );
            })}

            <Button
                type="button"
                variant="ghost"
                size="sm"
                onClick={onOpenPicker}
                disabled={atLimit}
            >
                <Plus className="size-4" />
                {t('Add player')}
            </Button>

            <div className="ml-auto flex min-w-0 flex-wrap items-center justify-end gap-2">
                <Button
                    type="button"
                    variant="ghost"
                    size="sm"
                    onClick={onCancel}
                >
                    {t('Cancel')}
                </Button>
                <Button
                    type="button"
                    size="sm"
                    onClick={onConfirm}
                    disabled={selectedIds.length === 0}
                >
                    {t('Compare (:count)', { count: selectedIds.length })}
                </Button>
            </div>
        </>
    );
}

function ComparingDock({
    playerCount,
    canAddMore,
    onAddPlayer,
    onEdit,
    onExit,
}: ComparingProps) {
    const { t } = useTranslation();

    return (
        <>
            <span className="font-display text-sm font-semibold">
                {playerCount === 1
                    ? t('Comparing :count player', { count: playerCount })
                    : t('Comparing :count players', { count: playerCount })}
            </span>

            <div className="ml-auto flex min-w-0 flex-wrap items-center justify-end gap-2">
                {canAddMore && (
                    <Button
                        type="button"
                        variant="ghost"
                        size="sm"
                        onClick={onAddPlayer}
                    >
                        <Plus className="size-4" />
                        {t('Add')}
                    </Button>
                )}
                <Button
                    type="button"
                    variant="ghost"
                    size="sm"
                    onClick={onEdit}
                >
                    <Pencil className="size-4" />
                    {t('Edit')}
                </Button>
                <Button
                    type="button"
                    variant="solid"
                    size="sm"
                    onClick={onExit}
                >
                    <X className="size-4" />
                    {t('Exit compare')}
                </Button>
            </div>
        </>
    );
}
