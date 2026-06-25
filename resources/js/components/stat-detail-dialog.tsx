import type { LucideIcon } from 'lucide-react';
import PlayerAvatar from '@/components/player-avatar';
import {
    Dialog,
    DialogContent,
    DialogDescription,
    DialogHeader,
    DialogTitle,
} from '@/components/ui/dialog';
import { cn } from '@/lib/utils';

/** A single player on a stat card's detail list, normalised from either page's row shape. */
export interface StatDetailPlayer {
    id: number;
    name: string;
    initials: string;
    avatar: string | null;
    isMe: boolean;
    /** The player's metric, already formatted for display (e.g. "+12 pts", "3 places"). */
    valueText: string;
}

type Tone = 'gold' | 'green' | 'muted' | 'destructive';

const TONE_CLASS: Record<Tone, string> = {
    gold: 'text-accent',
    green: 'text-primary',
    muted: 'text-muted-foreground',
    destructive: 'text-destructive',
};

/**
 * The detail surface for a superlative stat card (top earner, biggest climber, …). A centred modal on
 * desktop and a bottom sheet on mobile (via the shared {@link Dialog}). It names the card and its metric,
 * then lists every tied player — useful precisely when a card shows "6 players" and you want to know who.
 */
export default function StatDetailDialog({
    open,
    onOpenChange,
    icon: Icon,
    tone,
    title,
    explanation,
    summary,
    players,
}: {
    open: boolean;
    onOpenChange: (open: boolean) => void;
    icon: LucideIcon;
    tone: Tone;
    title: string;
    /** A plain-language sentence explaining what the card measures. */
    explanation: string;
    /** A short headline of the tie — count plus the shared value (e.g. "6 players · +12 pts"). */
    summary: string;
    players: StatDetailPlayer[];
}) {
    return (
        <Dialog open={open} onOpenChange={onOpenChange}>
            <DialogContent className="sm:max-w-md">
                <DialogHeader>
                    <DialogTitle className="flex items-center justify-center gap-2 font-display sm:justify-start">
                        <Icon className={cn('size-5', TONE_CLASS[tone])} />
                        {title}
                    </DialogTitle>
                    <DialogDescription>{explanation}</DialogDescription>
                </DialogHeader>

                <p className="text-xs font-bold tracking-[0.12em] text-muted-foreground uppercase">
                    {summary}
                </p>

                <ul className="-mt-2 flex flex-col gap-2">
                    {players.map((player, index) => (
                        <li
                            key={player.id}
                            className={cn(
                                'flex items-center gap-3 rounded-2xl border px-3 py-2.5',
                                player.isMe
                                    ? 'border-accent bg-accent/10'
                                    : 'border-border bg-card',
                            )}
                        >
                            <span className="w-5 shrink-0 text-center font-display text-sm font-semibold text-muted-foreground tabular-nums">
                                {index + 1}
                            </span>
                            <PlayerAvatar
                                name={player.name}
                                initials={player.initials}
                                src={player.avatar}
                                fallbackClassName={
                                    player.isMe
                                        ? 'bg-pitch-deep text-white'
                                        : 'bg-brand-gradient text-white'
                                }
                                className="size-9"
                            />
                            <span className="min-w-0 flex-1 truncate font-display font-semibold text-foreground">
                                {player.name}
                            </span>
                            <span className="shrink-0 font-display text-sm font-semibold text-foreground tabular-nums">
                                {player.valueText}
                            </span>
                        </li>
                    ))}
                </ul>
            </DialogContent>
        </Dialog>
    );
}
