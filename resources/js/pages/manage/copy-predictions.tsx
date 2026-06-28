import { Head, Link, router } from '@inertiajs/react';
import { ArrowRight, ArrowRightLeft, Check } from 'lucide-react';
import { useState } from 'react';
import { PoolIdentity } from '@/components/pool-identity';
import { Button } from '@/components/ui/button';
import { Label } from '@/components/ui/label';
import { useTranslation } from '@/hooks/use-translation';
import { resolveAccent } from '@/lib/accents';
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

interface CopyPredictionsProps {
    tournament: { name: string; slug: string };
    pools: PoolOption[];
}

function PoolSelect({
    pools,
    value,
    disabledId,
    onSelect,
    error,
}: {
    pools: PoolOption[];
    value: number | null;
    disabledId: number | null;
    onSelect: (id: number) => void;
    error?: string;
}) {
    return (
        <div className="flex flex-col gap-2">
            {pools.map((pool) => {
                const accent = resolveAccent(pool.accent);
                const isSelected = value === pool.id;
                const isDisabled = disabledId === pool.id;

                return (
                    <button
                        key={pool.id}
                        type="button"
                        onClick={() => onSelect(pool.id)}
                        disabled={isDisabled}
                        aria-pressed={isSelected}
                        className={cn(
                            'press flex w-full items-center gap-3 rounded-2xl border px-4 py-3 text-left transition-colors',
                            isDisabled && 'cursor-not-allowed opacity-40',
                            isSelected
                                ? cn('bg-primary/[0.06]', accent.ringClass)
                                : 'border-border hover:border-primary/40',
                        )}
                    >
                        <PoolIdentity
                            source={pool.source}
                            name={pool.name}
                            scoringLabel={pool.scoring_label}
                            accent={pool.accent}
                            variant="compact"
                            className="min-w-0 flex-1"
                        />
                        <span
                            className={cn(
                                'grid size-5 shrink-0 place-items-center rounded-full border',
                                isSelected
                                    ? 'border-primary bg-primary text-white'
                                    : 'border-border',
                            )}
                        >
                            {isSelected && <Check className="size-3.5" />}
                        </span>
                    </button>
                );
            })}
            {error && (
                <p className="text-xs font-medium text-destructive">{error}</p>
            )}
        </div>
    );
}

export default function CopyPredictions({
    tournament,
    pools,
}: CopyPredictionsProps) {
    const { t } = useTranslation();
    const [sourceId, setSourceId] = useState<number | null>(null);
    const [destinationId, setDestinationId] = useState<number | null>(null);
    const [submitting, setSubmitting] = useState(false);
    const [errors, setErrors] = useState<Record<string, string>>({});

    const submit = () => {
        setSubmitting(true);
        setErrors({});
        router.post(
            manage.copy.preview(tournament.slug).url,
            { source_pool_id: sourceId, destination_pool_id: destinationId },
            {
                onError: (formErrors) => setErrors(formErrors),
                onFinish: () => setSubmitting(false),
            },
        );
    };

    const canSubmit =
        sourceId !== null &&
        destinationId !== null &&
        sourceId !== destinationId;

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
                        <h1 className="text-3xl font-semibold tracking-tight text-foreground sm:text-4xl">
                            {t(tournament.name)}
                        </h1>
                        <p className="max-w-xl text-sm text-muted-foreground">
                            {t(
                                'Copy players’ predictions from one pool into another pool of this tournament. Pick the pool to copy from and the pool to copy into, then choose which players to import.',
                            )}
                        </p>
                    </div>
                </header>

                <div className="flex flex-col gap-5 rounded-3xl border border-border bg-card p-5 shadow-[var(--sh-sm)] sm:p-6">
                    {pools.length < 2 ? (
                        <p className="text-sm text-muted-foreground">
                            {t(
                                'This tournament needs at least two pools to copy between.',
                            )}
                        </p>
                    ) : (
                        <>
                            <div className="flex flex-col gap-2">
                                <Label>{t('Copy from')}</Label>
                                <PoolSelect
                                    pools={pools}
                                    value={sourceId}
                                    disabledId={destinationId}
                                    onSelect={setSourceId}
                                    error={errors.source_pool_id}
                                />
                            </div>

                            <div className="flex flex-col gap-2">
                                <Label>{t('Copy into')}</Label>
                                <PoolSelect
                                    pools={pools}
                                    value={destinationId}
                                    disabledId={sourceId}
                                    onSelect={setDestinationId}
                                    error={errors.destination_pool_id}
                                />
                            </div>

                            <div className="flex items-center justify-end gap-2">
                                <Button variant="ghost" asChild>
                                    <Link href={manage.index().url}>
                                        {t('Cancel')}
                                    </Link>
                                </Button>
                                <Button
                                    onClick={submit}
                                    disabled={!canSubmit || submitting}
                                >
                                    {submitting
                                        ? t('Loading…')
                                        : t('Choose players')}
                                    <ArrowRight className="size-4" />
                                </Button>
                            </div>
                        </>
                    )}
                </div>
            </div>
        </>
    );
}

const breadcrumbs: BreadcrumbItem[] = [
    { title: 'Manage', href: manage.index() },
];

CopyPredictions.layout = { breadcrumbs };
