import type { ReactNode } from 'react';
import { Flag } from '@/components/flag';
import { useTranslation } from '@/hooks/use-translation';
import { cn } from '@/lib/utils';
import type { TeamRef } from '@/types/pools';

/**
 * One team on its own line — flag + name on the left, its score control on the right. Used by both
 * admin score-entry cards (live control and review) so each score sits beside the team it belongs
 * to, with no doubt which is which and no horizontal overflow on mobile. Mirrors the read-only
 * per-team row of a settled fixture card ({@see SettledKnockoutTeam}).
 */
export function TeamScoreRow({
    team,
    label,
    children,
    className,
}: {
    team: TeamRef | null;
    label?: string | null;
    /** The score control for this team (a stepper, a number input, or a static value). */
    children: ReactNode;
    className?: string;
}) {
    const { tCountry } = useTranslation();

    return (
        <div
            className={cn(
                'flex items-center justify-between gap-3 py-2',
                className,
            )}
        >
            <span className="flex min-w-0 items-center gap-2.5 text-sm font-semibold">
                <Flag team={team} className="h-5 w-7 rounded-[3px]" />
                <span className="truncate">
                    {team ? tCountry(team.code, team.name) : (label ?? 'TBD')}
                </span>
            </span>
            <div className="shrink-0">{children}</div>
        </div>
    );
}
