import PlayerAvatar from '@/components/player-avatar';
import { cn } from '@/lib/utils';

export interface StackPlayer {
    id: number;
    name: string;
    initials: string;
    avatar?: string | null;
    isMe?: boolean;
}

/**
 * Up to three overlapping player avatars — used to show a tie on a stat card (e.g. "6 players tied").
 * A single player renders as one avatar (the same look as a lone board-leader). Pass the leaders in
 * display order; extras beyond three are dropped (the caller shows the real count alongside). The
 * `sm` size keeps the stack narrow inside compact, half-width cards so the headline value still fits.
 */
export function AvatarStack({
    players,
    size = 'md',
    className,
}: {
    players: StackPlayer[];
    size?: 'sm' | 'md';
    className?: string;
}) {
    return (
        <div className={cn('flex shrink-0', className)}>
            {players.slice(0, 3).map((player, index) => (
                <PlayerAvatar
                    key={player.id}
                    name={player.name}
                    initials={player.initials}
                    src={player.avatar}
                    fallbackClassName={
                        player.isMe
                            ? 'bg-pitch-deep text-white'
                            : 'bg-brand-gradient text-white'
                    }
                    ringClassName="ring-2 ring-card"
                    className={cn(
                        size === 'sm' ? 'size-7' : 'size-9',
                        index > 0 && (size === 'sm' ? '-ml-1.5' : '-ml-2'),
                    )}
                />
            ))}
        </div>
    );
}
