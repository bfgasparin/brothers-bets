import { Head, Link, router } from '@inertiajs/react';
import { ArrowRight, ArrowRightLeft, Info, Users } from 'lucide-react';
import { useState } from 'react';
import PlayerAvatar from '@/components/player-avatar';
import { Button } from '@/components/ui/button';
import { Checkbox } from '@/components/ui/checkbox';
import { useInitials } from '@/hooks/use-initials';
import { useTranslation } from '@/hooks/use-translation';
import { cn } from '@/lib/utils';
import manage from '@/routes/manage';
import type { BreadcrumbItem } from '@/types/navigation';

interface PoolOption {
    id: number;
    name: string;
    source: string;
    slug: string;
    accent: string | null;
    scoring_label: string;
}

interface Candidate {
    user: {
        id: number;
        name: string;
        email: string | null;
        avatar: string | null;
    };
    group_count: number;
    source_authored: boolean;
    destination_joined: boolean;
    destination_populated: boolean;
}

interface ReviewProps {
    tournament: { name: string; slug: string };
    source: PoolOption;
    destination: PoolOption;
    knockout_transfers: boolean;
    candidates: Candidate[];
}

export default function CopyPredictionsReview({
    tournament,
    source,
    destination,
    knockout_transfers: knockoutTransfers,
    candidates,
}: ReviewProps) {
    const { t } = useTranslation();
    const getInitials = useInitials();

    // Default selection: players with predictions who aren't already populated in the destination.
    const [selected, setSelected] = useState<Set<number>>(
        () =>
            new Set(
                candidates
                    .filter(
                        (candidate) =>
                            !candidate.destination_populated &&
                            candidate.group_count > 0,
                    )
                    .map((candidate) => candidate.user.id),
            ),
    );
    const [overwrite, setOverwrite] = useState(false);
    const [submitting, setSubmitting] = useState(false);

    const toggle = (id: number) => {
        setSelected((prev) => {
            const next = new Set(prev);

            if (next.has(id)) {
                next.delete(id);
            } else {
                next.add(id);
            }

            return next;
        });
    };

    const selectAll = () =>
        setSelected(new Set(candidates.map((candidate) => candidate.user.id)));
    const selectNone = () => setSelected(new Set());

    const submit = () => {
        setSubmitting(true);
        router.post(
            manage.copy.commit(tournament.slug).url,
            {
                source_pool_id: source.id,
                destination_pool_id: destination.id,
                overwrite,
                user_ids: Array.from(selected),
            },
            { onFinish: () => setSubmitting(false) },
        );
    };

    return (
        <>
            <Head title={`${t('Copy predictions')} · ${t(tournament.name)}`} />
            <div className="flex h-full flex-1 flex-col gap-6 p-4 sm:p-6 lg:p-8">
                <header className="hero relative overflow-hidden rounded-3xl border border-border p-8">
                    <div className="hero-lines" />
                    <div className="relative flex flex-col gap-3">
                        <span className="inline-flex items-center gap-2 text-xs font-bold tracking-[0.14em] text-muted-foreground uppercase">
                            <ArrowRightLeft className="size-4 text-primary" />
                            {t('Copy predictions')}
                        </span>
                        <h1 className="flex flex-wrap items-center gap-x-2 gap-y-1 text-2xl font-semibold tracking-tight text-foreground sm:text-3xl">
                            <span>{source.name}</span>
                            <ArrowRight className="size-5 text-muted-foreground" />
                            <span>{destination.name}</span>
                        </h1>
                        <p className="max-w-xl text-sm text-muted-foreground">
                            {t(
                                'Choose which players to copy. Each player’s own predictions in :source are imported into :destination.',
                                {
                                    source: source.name,
                                    destination: destination.name,
                                },
                            )}
                        </p>
                    </div>
                </header>

                {!knockoutTransfers && (
                    <div className="flex items-start gap-3 rounded-2xl border border-amber-300/60 bg-amber-50 p-4 text-sm text-amber-900 dark:border-amber-400/30 dark:bg-amber-950/40 dark:text-amber-200">
                        <Info className="mt-0.5 size-4 shrink-0" />
                        <p>
                            {t(
                                'These pools use different knockout formats — only group-stage predictions will be copied.',
                            )}
                        </p>
                    </div>
                )}

                <div className="flex flex-col gap-4 rounded-3xl border border-border bg-card p-5 shadow-[var(--sh-sm)] sm:p-6">
                    <div className="flex flex-wrap items-center justify-between gap-3">
                        <span className="inline-flex items-center gap-2 text-sm font-semibold text-foreground">
                            <Users className="size-4 text-primary" />
                            {t(':count of :total selected', {
                                count: selected.size,
                                total: candidates.length,
                            })}
                        </span>
                        <div className="flex items-center gap-2">
                            <Button
                                variant="outline"
                                size="sm"
                                onClick={selectAll}
                            >
                                {t('Select all')}
                            </Button>
                            <Button
                                variant="outline"
                                size="sm"
                                onClick={selectNone}
                            >
                                {t('Select none')}
                            </Button>
                        </div>
                    </div>

                    {candidates.length === 0 ? (
                        <p className="py-8 text-center text-sm text-muted-foreground">
                            {t('No players have joined this pool yet.')}
                        </p>
                    ) : (
                        <ul className="flex flex-col gap-1">
                            {candidates.map((candidate) => {
                                const isSelected = selected.has(
                                    candidate.user.id,
                                );
                                const willSkip =
                                    isSelected &&
                                    candidate.destination_populated &&
                                    !overwrite;
                                const nothingToCopy =
                                    candidate.group_count === 0;

                                return (
                                    <li key={candidate.user.id}>
                                        <button
                                            type="button"
                                            onClick={() =>
                                                toggle(candidate.user.id)
                                            }
                                            aria-pressed={isSelected}
                                            className={cn(
                                                'press flex w-full items-center gap-3 rounded-xl border px-3 py-2.5 text-left transition-colors',
                                                isSelected
                                                    ? 'border-primary/40 bg-primary/[0.06]'
                                                    : 'border-transparent hover:bg-muted',
                                            )}
                                        >
                                            <Checkbox
                                                checked={isSelected}
                                                tabIndex={-1}
                                                className="pointer-events-none"
                                            />
                                            <PlayerAvatar
                                                name={candidate.user.name}
                                                initials={getInitials(
                                                    candidate.user.name,
                                                )}
                                                src={candidate.user.avatar}
                                                fallbackClassName="bg-brand-gradient text-white"
                                                className="size-9"
                                            />
                                            <span className="min-w-0 flex-1">
                                                <span className="flex items-center gap-1.5 font-display font-semibold">
                                                    <span className="truncate">
                                                        {candidate.user.name}
                                                    </span>
                                                    {candidate.source_authored && (
                                                        <span
                                                            title={t(
                                                                'Bracket imported as authored',
                                                            )}
                                                            className="inline-flex shrink-0 items-center rounded-full bg-accent/15 px-2 py-0.5 text-[10px] font-bold tracking-wide text-[#8a5a00] uppercase dark:text-amber-300"
                                                        >
                                                            {t('Authored')}
                                                        </span>
                                                    )}
                                                </span>
                                                <span className="block truncate text-xs text-muted-foreground">
                                                    {nothingToCopy
                                                        ? t(
                                                              'No predictions yet',
                                                          )
                                                        : t(
                                                              ':count predictions',
                                                              {
                                                                  count: candidate.group_count,
                                                              },
                                                          )}
                                                </span>
                                            </span>
                                            {candidate.destination_populated && (
                                                <span
                                                    className={cn(
                                                        'shrink-0 rounded-full px-2.5 py-0.5 text-[11px] font-semibold',
                                                        willSkip
                                                            ? 'bg-muted text-muted-foreground'
                                                            : 'bg-amber-100 text-amber-900 dark:bg-amber-950/50 dark:text-amber-200',
                                                    )}
                                                >
                                                    {willSkip
                                                        ? t('Will skip')
                                                        : t(
                                                              'Already has picks',
                                                          )}
                                                </span>
                                            )}
                                        </button>
                                    </li>
                                );
                            })}
                        </ul>
                    )}

                    <label className="flex cursor-pointer items-start gap-3 rounded-2xl border border-border bg-muted/30 p-4">
                        <Checkbox
                            checked={overwrite}
                            onCheckedChange={(value) =>
                                setOverwrite(value === true)
                            }
                            className="mt-0.5"
                        />
                        <span className="flex flex-col gap-0.5">
                            <span className="text-sm font-semibold text-foreground">
                                {t(
                                    'Overwrite players who already have predictions',
                                )}
                            </span>
                            <span className="text-xs text-muted-foreground">
                                {t(
                                    'When off, players who already have predictions in this pool are left untouched.',
                                )}
                            </span>
                        </span>
                    </label>

                    <div className="flex items-center justify-end gap-2">
                        <Button variant="ghost" asChild>
                            <Link
                                href={manage.copy.create(tournament.slug).url}
                            >
                                {t('Back')}
                            </Link>
                        </Button>
                        <Button
                            onClick={submit}
                            disabled={selected.size === 0 || submitting}
                        >
                            {submitting ? t('Copying…') : t('Copy & re-score')}
                            <ArrowRight className="size-4" />
                        </Button>
                    </div>
                </div>
            </div>
        </>
    );
}

const breadcrumbs: BreadcrumbItem[] = [
    { title: 'Manage', href: manage.index() },
];

CopyPredictionsReview.layout = { breadcrumbs };
