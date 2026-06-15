import { Check, ChevronsUpDown, Search } from 'lucide-react';
import { useState } from 'react';
import PlayerAvatar from '@/components/player-avatar';
import {
    Dialog,
    DialogContent,
    DialogDescription,
    DialogHeader,
    DialogTitle,
} from '@/components/ui/dialog';
import { Input } from '@/components/ui/input';
import { useInitials } from '@/hooks/use-initials';
import { useTranslation } from '@/hooks/use-translation';
import { cn } from '@/lib/utils';

export interface PlayerOption {
    id: number;
    name: string;
    email: string | null;
    avatar: string | null;
    // Set when this player's entry was backfilled "as authored" — badged so admins can spot whose
    // accounts had the group-classification problem.
    has_authored_import?: boolean;
}

function AuthoredBadge() {
    const { t } = useTranslation();

    return (
        <span
            title={t('Bracket imported as authored')}
            className="inline-flex shrink-0 items-center rounded-full bg-accent/15 px-2 py-0.5 text-[10px] font-bold tracking-wide text-[#8a5a00] uppercase dark:text-amber-300"
        >
            {t('Authored')}
        </span>
    );
}

/**
 * A branded, searchable single-select for picking a player — a field-style trigger showing the chosen
 * player's avatar + name + email, opening a dialog (a bottom sheet on mobile) with a search box and an
 * avatar list. Adapts the multi-select {@link AddPlayerDialog} pattern to a single choice.
 */
export function PlayerPicker({
    players,
    value,
    onSelect,
    id,
    invalid = false,
    placeholder,
}: {
    players: PlayerOption[];
    value: number | null;
    onSelect: (id: number) => void;
    id?: string;
    invalid?: boolean;
    placeholder?: string;
}) {
    const { t } = useTranslation();
    const getInitials = useInitials();
    const [open, setOpen] = useState(false);
    const [query, setQuery] = useState('');

    // Start each opening from a clean search (adjust-during-render, like AddPlayerDialog).
    const [wasOpen, setWasOpen] = useState(open);

    if (open !== wasOpen) {
        setWasOpen(open);

        if (open) {
            setQuery('');
        }
    }

    const selected = players.find((player) => player.id === value) ?? null;

    const q = query.trim().toLowerCase();
    const filtered = q
        ? players.filter(
              (player) =>
                  player.name.toLowerCase().includes(q) ||
                  (player.email ?? '').toLowerCase().includes(q),
          )
        : players;

    return (
        <>
            <button
                type="button"
                id={id}
                onClick={() => setOpen(true)}
                aria-haspopup="dialog"
                className={cn(
                    'press flex w-full items-center gap-3 rounded-xl border bg-background px-3 py-2 text-left text-sm shadow-xs transition-colors outline-none focus-visible:border-ring focus-visible:ring-[3px] focus-visible:ring-ring/50',
                    invalid ? 'border-destructive' : 'border-input',
                )}
            >
                {selected ? (
                    <>
                        <PlayerAvatar
                            name={selected.name}
                            initials={getInitials(selected.name)}
                            src={selected.avatar}
                            fallbackClassName="bg-brand-gradient text-white"
                            className="size-8"
                        />
                        <span className="min-w-0 flex-1">
                            <span className="flex items-center gap-1.5 font-display font-semibold text-foreground">
                                <span className="truncate">
                                    {selected.name}
                                </span>
                                {selected.has_authored_import && (
                                    <AuthoredBadge />
                                )}
                            </span>
                            <span className="block truncate text-xs text-muted-foreground">
                                {selected.email ?? t('No email')}
                            </span>
                        </span>
                    </>
                ) : (
                    <span className="flex-1 py-1 text-muted-foreground">
                        {placeholder ?? t('Select a player…')}
                    </span>
                )}
                <ChevronsUpDown className="size-4 shrink-0 text-muted-foreground" />
            </button>

            <Dialog open={open} onOpenChange={setOpen}>
                <DialogContent className="sm:max-w-md">
                    <DialogHeader>
                        <DialogTitle>{t('Select a player')}</DialogTitle>
                        <DialogDescription>
                            {t('Pick the player to backfill predictions for.')}
                        </DialogDescription>
                    </DialogHeader>

                    <div className="relative">
                        <Search className="absolute top-1/2 left-3 size-4 -translate-y-1/2 text-muted-foreground" />
                        <Input
                            value={query}
                            onChange={(event) => setQuery(event.target.value)}
                            placeholder={t('Search by name or email…')}
                            aria-label={t('Search players')}
                            className="pl-9"
                            autoFocus
                        />
                    </div>

                    <div className="-mx-1 max-h-[360px] overflow-y-auto px-1">
                        {filtered.length === 0 ? (
                            <p className="py-8 text-center text-sm text-muted-foreground">
                                {t('No players found.')}
                            </p>
                        ) : (
                            <ul className="flex flex-col gap-1">
                                {filtered.map((player) => {
                                    const isSelected = player.id === value;

                                    return (
                                        <li key={player.id}>
                                            <button
                                                type="button"
                                                onClick={() => {
                                                    onSelect(player.id);
                                                    setOpen(false);
                                                }}
                                                aria-pressed={isSelected}
                                                className={cn(
                                                    'press flex w-full cursor-pointer items-center gap-3 rounded-xl border px-3 py-2 text-left transition-colors',
                                                    isSelected
                                                        ? 'border-primary/40 bg-primary/[0.06]'
                                                        : 'border-transparent hover:bg-muted',
                                                )}
                                            >
                                                <PlayerAvatar
                                                    name={player.name}
                                                    initials={getInitials(
                                                        player.name,
                                                    )}
                                                    src={player.avatar}
                                                    fallbackClassName="bg-brand-gradient text-white"
                                                    className="size-9"
                                                />
                                                <span className="min-w-0 flex-1">
                                                    <span className="flex items-center gap-1.5 font-display font-semibold">
                                                        <span className="truncate">
                                                            {player.name}
                                                        </span>
                                                        {player.has_authored_import && (
                                                            <AuthoredBadge />
                                                        )}
                                                    </span>
                                                    <span className="block truncate text-xs text-muted-foreground">
                                                        {player.email ??
                                                            t('No email')}
                                                    </span>
                                                </span>
                                                <span
                                                    className={cn(
                                                        'grid size-5 shrink-0 place-items-center rounded-full border',
                                                        isSelected
                                                            ? 'border-primary bg-primary text-white'
                                                            : 'border-border',
                                                    )}
                                                >
                                                    {isSelected && (
                                                        <Check className="size-3.5" />
                                                    )}
                                                </span>
                                            </button>
                                        </li>
                                    );
                                })}
                            </ul>
                        )}
                    </div>
                </DialogContent>
            </Dialog>
        </>
    );
}
