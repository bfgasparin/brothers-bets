import { Head, Link } from '@inertiajs/react';
import {
    ArrowRightLeft,
    CalendarClock,
    ChevronRight,
    ClipboardCheck,
    Radio,
    Trophy,
    Upload,
    Users,
} from 'lucide-react';
import type { LucideIcon } from 'lucide-react';
import { Button } from '@/components/ui/button';
import { useTranslation } from '@/hooks/use-translation';
import manage from '@/routes/manage';
import type { BreadcrumbItem } from '@/types/navigation';

interface ManageTournament {
    name: string;
    slug: string;
    status: string;
    status_label: string;
}

interface ManageIndexProps {
    tournaments: ManageTournament[];
}

function AdminAction({
    href,
    icon: Icon,
    label,
}: {
    href: string;
    icon: LucideIcon;
    label: string;
}) {
    return (
        <Button asChild variant="outline" className="justify-start">
            <Link href={href}>
                <Icon className="size-4 text-primary" />
                {label}
            </Link>
        </Button>
    );
}

export default function ManageIndex({ tournaments }: ManageIndexProps) {
    const { t } = useTranslation();

    return (
        <>
            <Head title={t('Manage')} />
            <div className="relative min-h-full bg-background">
                <div className="w-full px-4 py-6 sm:px-6 sm:py-8 lg:px-8 xl:px-10">
                    <header className="hero relative mb-6 overflow-hidden rounded-3xl border border-border p-5 sm:mb-8 sm:p-8">
                        <div className="hero-lines" />
                        <div className="relative flex flex-col gap-3">
                            <span className="inline-flex w-fit items-center gap-2 text-xs font-bold tracking-[0.14em] text-muted-foreground uppercase">
                                <Trophy className="size-4 text-primary" />
                                {t('Admin')}
                            </span>
                            <h1 className="text-3xl font-semibold tracking-tight text-balance text-foreground sm:text-5xl">
                                {t('Manage tournaments')}
                            </h1>
                            <span className="bg-gold-gradient mt-1 h-1 w-12 rounded-full" />
                            <p className="max-w-2xl text-sm text-muted-foreground sm:text-base">
                                {t(
                                    'Run a tournament from here — start matches in Live Control, review and approve scores, and reschedule fixtures. No pool membership needed.',
                                )}
                            </p>
                        </div>
                    </header>

                    <Link
                        href={manage.players.index().url}
                        className="card-elevated mb-6 flex items-center justify-between gap-4 rounded-3xl border border-border p-6 transition-colors hover:bg-muted/50 sm:mb-8"
                    >
                        <div className="flex min-w-0 items-center gap-4">
                            <span className="bg-gold-gradient flex size-11 shrink-0 items-center justify-center rounded-2xl text-white shadow-[var(--sh-sm)]">
                                <Users className="size-5" />
                            </span>
                            <div className="flex min-w-0 flex-col">
                                <h2 className="text-lg font-semibold tracking-tight text-foreground">
                                    {t('Players')}
                                </h2>
                                <p className="text-sm text-muted-foreground">
                                    {t(
                                        'Pre-register players, join them to pools, and set their login email.',
                                    )}
                                </p>
                            </div>
                        </div>
                        <ChevronRight className="size-5 shrink-0 text-muted-foreground" />
                    </Link>

                    {tournaments.length > 0 ? (
                        <div className="grid gap-4 sm:grid-cols-2 lg:grid-cols-3">
                            {tournaments.map((tournament) => (
                                <div
                                    key={tournament.slug}
                                    className="card-elevated flex flex-col gap-5 rounded-3xl border border-border p-6"
                                >
                                    <div className="flex items-start justify-between gap-3">
                                        <h2 className="text-xl font-semibold tracking-tight text-balance text-foreground">
                                            {t(tournament.name)}
                                        </h2>
                                        <span className="shrink-0 rounded-full bg-secondary px-3 py-1 font-display text-xs font-semibold text-secondary-foreground">
                                            {tournament.status_label}
                                        </span>
                                    </div>
                                    <div className="mt-auto flex flex-col gap-2">
                                        <AdminAction
                                            href={
                                                manage.live.index(
                                                    tournament.slug,
                                                ).url
                                            }
                                            icon={Radio}
                                            label={t('Live control')}
                                        />
                                        <AdminAction
                                            href={
                                                manage.scores.review(
                                                    tournament.slug,
                                                ).url
                                            }
                                            icon={ClipboardCheck}
                                            label={t('Review scores')}
                                        />
                                        <AdminAction
                                            href={
                                                manage.schedule.index(
                                                    tournament.slug,
                                                ).url
                                            }
                                            icon={CalendarClock}
                                            label={t('Schedule')}
                                        />
                                        <AdminAction
                                            href={
                                                manage.backfill.create(
                                                    tournament.slug,
                                                ).url
                                            }
                                            icon={Upload}
                                            label={t('Backfill predictions')}
                                        />
                                        <AdminAction
                                            href={
                                                manage.copy.create(
                                                    tournament.slug,
                                                ).url
                                            }
                                            icon={ArrowRightLeft}
                                            label={t('Copy predictions')}
                                        />
                                    </div>
                                </div>
                            ))}
                        </div>
                    ) : (
                        <p className="text-sm text-muted-foreground">
                            {t('No tournaments yet.')}
                        </p>
                    )}
                </div>
            </div>
        </>
    );
}

const breadcrumbs: BreadcrumbItem[] = [
    { title: 'Manage', href: manage.index() },
];

ManageIndex.layout = { breadcrumbs };
