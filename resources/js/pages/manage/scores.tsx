import { Head, router } from '@inertiajs/react';
import { ClipboardCheck, Trophy } from 'lucide-react';
import { useState } from 'react';
import { Flag } from '@/components/flag';
import { ScoreStepper } from '@/components/score-stepper';
import { StandingsTable } from '@/components/standings-table';
import { TeamScoreRow } from '@/components/team-score-row';
import { TieResolutionPanel } from '@/components/tie-resolution-panel';
import { Button } from '@/components/ui/button';
import { ToggleGroup, ToggleGroupItem } from '@/components/ui/toggle-group';
import { useTranslation } from '@/hooks/use-translation';
import manage from '@/routes/manage';
import type { BreadcrumbItem } from '@/types/navigation';
import type { StandingRow, TeamRef, TiedCluster } from '@/types/pools';

interface ReviewRowData {
    fixture_id: number;
    match_number: number;
    phase: string;
    is_knockout: boolean;
    status: string;
    has_ended: boolean;
    kicks_off_at: string | null;
    home: TeamRef | null;
    away: TeamRef | null;
    home_label: string | null;
    away_label: string | null;
    proposal: {
        home_goals: number | null;
        away_goals: number | null;
        winner_team_id: number | null;
        home_penalties: number | null;
        away_penalties: number | null;
        status: string;
    } | null;
}

interface TiedGroupData {
    name: string;
    standings: StandingRow[];
    tied: TiedCluster[];
}

interface ThirdsTieData {
    teams: TeamRef[];
    resolved: boolean;
}

interface ReviewPageProps {
    tournament: {
        slug: string;
        name: string;
        status: string;
    };
    rows: ReviewRowData[];
    tied_groups: TiedGroupData[];
    thirds_tie: ThirdsTieData | null;
}

function toNumberOrNull(value: string): number | null {
    return value === '' ? null : Number(value);
}

function ReviewRow({ row, slug }: { row: ReviewRowData; slug: string }) {
    const { t, tCountry } = useTranslation();
    const [home, setHome] = useState(
        row.proposal?.home_goals?.toString() ?? '',
    );
    const [away, setAway] = useState(
        row.proposal?.away_goals?.toString() ?? '',
    );
    const [winner, setWinner] = useState<string>(
        row.proposal?.winner_team_id?.toString() ?? '',
    );
    const [saving, setSaving] = useState(false);
    const [saved, setSaved] = useState(false);
    const [error, setError] = useState<string | null>(null);

    const save = (rejected: boolean) => {
        setSaving(true);
        setSaved(false);
        setError(null);
        router.patch(
            manage.scores.proposal({
                tournament: slug,
                fixture: row.fixture_id,
            }).url,
            {
                home_goals: toNumberOrNull(home),
                away_goals: toNumberOrNull(away),
                winner_team_id: winner === '' ? null : Number(winner),
                rejected,
            },
            {
                preserveScroll: true,
                onSuccess: () => setSaved(true),
                onError: (formErrors) =>
                    setError(
                        Object.values(formErrors)[0] ??
                            t('Could not save this score.'),
                    ),
                onFinish: () => setSaving(false),
            },
        );
    };

    const rejected = row.proposal?.status === 'rejected';

    // Mirror the prediction flow: who advances is derived from the score. A decisive result picks
    // the higher-scoring side (shown read-only); only a draw asks the admin to choose.
    const teamsKnown = row.home !== null && row.away !== null;
    const bothScored = home !== '' && away !== '';
    const isDraw = bothScored && Number(home) === Number(away);
    const decisiveWinnerId =
        bothScored && !isDraw && teamsKnown
            ? Number(home) > Number(away)
                ? row.home!.id
                : row.away!.id
            : null;
    const winnerTeam =
        decisiveWinnerId === row.home?.id
            ? row.home
            : decisiveWinnerId === row.away?.id
              ? row.away
              : null;

    const handleScore = (side: 'home' | 'away', value: string): void => {
        const nextHome = side === 'home' ? value : home;
        const nextAway = side === 'away' ? value : away;

        setError(null);

        if (side === 'home') {
            setHome(value);
        } else {
            setAway(value);
        }

        if (!row.is_knockout || !teamsKnown) {
            return;
        }

        // A draw keeps any existing manual pick; a decisive or incomplete score derives it.
        if (nextHome === '' || nextAway === '') {
            setWinner('');
        } else if (Number(nextHome) > Number(nextAway)) {
            setWinner(String(row.home!.id));
        } else if (Number(nextAway) > Number(nextHome)) {
            setWinner(String(row.away!.id));
        }
    };

    const stateLabel = row.has_ended
        ? t('Full time')
        : row.status === 'finished'
          ? t('Finished')
          : row.status === 'live'
            ? t('Live')
            : t('Scheduled');

    return (
        <div className="flex flex-col gap-3 border-b border-border p-4 last:border-0">
            <div className="flex flex-wrap items-center gap-2">
                <span className="font-display text-sm font-semibold">
                    {t('Match :number', { number: row.match_number })}
                </span>
                <span className="text-[11px] font-medium text-muted-foreground">
                    {t(row.phase)}
                </span>
                <span className="inline-flex w-fit items-center rounded-full bg-muted px-2 py-0.5 text-[10px] font-bold tracking-wide text-muted-foreground uppercase">
                    {stateLabel}
                </span>
            </div>

            <div>
                <TeamScoreRow team={row.home} label={row.home_label}>
                    <ScoreStepper
                        value={home}
                        onChange={(value) => handleScore('home', value)}
                        label={t(':team goals', {
                            team: row.home
                                ? tCountry(row.home.code, row.home.name)
                                : t('Home'),
                        })}
                    />
                </TeamScoreRow>
                <TeamScoreRow team={row.away} label={row.away_label}>
                    <ScoreStepper
                        value={away}
                        onChange={(value) => handleScore('away', value)}
                        label={t(':team goals', {
                            team: row.away
                                ? tCountry(row.away.code, row.away.name)
                                : t('Away'),
                        })}
                    />
                </TeamScoreRow>
            </div>

            {row.is_knockout && (
                <div className="flex flex-wrap items-center gap-2">
                    {!teamsKnown ? (
                        <span className="text-xs text-muted-foreground italic">
                            {t('Teams not set yet.')}
                        </span>
                    ) : !bothScored ? (
                        <span className="text-xs text-muted-foreground italic">
                            {t('Enter the score to set who advances.')}
                        </span>
                    ) : isDraw ? (
                        <>
                            <span className="text-[0.65rem] font-semibold tracking-wide text-muted-foreground uppercase">
                                {t('Advances on penalties')}
                            </span>
                            <ToggleGroup
                                type="single"
                                variant="outline"
                                size="sm"
                                value={winner}
                                onValueChange={(value) => setWinner(value)}
                                aria-label={t('Advancing team')}
                            >
                                <ToggleGroupItem
                                    value={String(row.home!.id)}
                                    className="gap-1 text-xs"
                                >
                                    <Flag team={row.home} className="h-4 w-6" />
                                    {tCountry(row.home!.code, row.home!.name)}
                                </ToggleGroupItem>
                                <ToggleGroupItem
                                    value={String(row.away!.id)}
                                    className="gap-1 text-xs"
                                >
                                    <Flag team={row.away} className="h-4 w-6" />
                                    {tCountry(row.away!.code, row.away!.name)}
                                </ToggleGroupItem>
                            </ToggleGroup>
                        </>
                    ) : (
                        <span className="inline-flex items-center gap-1.5 text-xs font-semibold text-pitch-deep dark:text-primary">
                            <span className="text-[0.65rem] font-semibold tracking-wide text-muted-foreground uppercase">
                                {t('Advances')}
                            </span>
                            <Flag team={winnerTeam} className="h-4 w-6" />
                            {winnerTeam &&
                                tCountry(winnerTeam.code, winnerTeam.name)}
                        </span>
                    )}
                </div>
            )}

            {error && (
                <p className="text-xs font-medium text-destructive">{error}</p>
            )}

            <div className="flex flex-wrap items-center justify-end gap-2">
                {saved && !rejected && (
                    <span className="text-xs font-semibold text-pitch-deep dark:text-primary">
                        {t('Saved')}
                    </span>
                )}
                {rejected && (
                    <span className="text-xs font-semibold text-muted-foreground">
                        {t('Skipped')}
                    </span>
                )}
                <Button
                    size="sm"
                    variant="outline"
                    disabled={saving}
                    onClick={() => save(false)}
                >
                    {saving ? t('Saving…') : t('Save')}
                </Button>
                <Button
                    size="sm"
                    variant="ghost"
                    disabled={saving}
                    onClick={() => save(true)}
                >
                    {t('Skip')}
                </Button>
            </div>
        </div>
    );
}

function TieResolutionSection({
    slug,
    tiedGroups,
    thirdsTie,
}: {
    slug: string;
    tiedGroups: TiedGroupData[];
    thirdsTie: ThirdsTieData | null;
}) {
    const { t } = useTranslation();

    if (tiedGroups.length === 0 && !thirdsTie) {
        return null;
    }

    const orderingUrl = manage.scores.ordering(slug).url;

    return (
        <div className="flex flex-col gap-5 rounded-3xl border border-amber/40 bg-card p-5">
            <div>
                <h2 className="font-display text-lg font-semibold">
                    {t('Resolve tied teams')}
                </h2>
                <p className="max-w-2xl text-sm text-muted-foreground">
                    {t(
                        "These results are level on every tiebreaker. Drag the tied teams into the official finishing order before approving — the bracket can't be published until you do.",
                    )}
                </p>
            </div>

            {tiedGroups.map((group) => {
                const teamById = new Map<number, TeamRef>();

                for (const row of group.standings) {
                    if (row.team) {
                        teamById.set(row.team.id, row.team);
                    }
                }

                const clusters = group.tied
                    .map((cluster) => ({
                        teams: cluster.team_ids
                            .map((id) => teamById.get(id))
                            .filter((team): team is TeamRef => Boolean(team)),
                        resolved: cluster.resolved,
                    }))
                    .filter((cluster) => cluster.teams.length > 1);

                return (
                    <div key={group.name} className="flex flex-col gap-3">
                        <h3 className="font-display text-sm font-semibold">
                            {t('Group :name', { name: group.name })}
                        </h3>
                        <StandingsTable standings={group.standings} />
                        <TieResolutionPanel
                            title={t('Order the tied teams')}
                            description={t(
                                'Drag to set the official finishing order.',
                            )}
                            clusters={clusters}
                            editable
                            url={orderingUrl}
                            payloadFor={(orderedTeamIds) => ({
                                scope: 'within-group',
                                group: group.name,
                                ordered_team_ids: orderedTeamIds,
                            })}
                        />
                    </div>
                );
            })}

            {thirdsTie && (
                <TieResolutionPanel
                    title={t('Order the tied third-placed teams')}
                    description={t(
                        'These third-placed teams are level across the qualifying cut. Drag them into order — the highest take the last spots.',
                    )}
                    clusters={[
                        {
                            teams: thirdsTie.teams,
                            resolved: thirdsTie.resolved,
                        },
                    ]}
                    editable
                    url={orderingUrl}
                    payloadFor={(orderedTeamIds) => ({
                        scope: 'thirds',
                        ordered_team_ids: orderedTeamIds,
                    })}
                />
            )}
        </div>
    );
}

export default function ScoreReview({
    tournament,
    rows,
    tied_groups: tiedGroups,
    thirds_tie: thirdsTie,
}: ReviewPageProps) {
    const { t } = useTranslation();
    const [approving, setApproving] = useState(false);
    const [errors, setErrors] = useState<Record<string, string>>({});

    const approve = () => {
        setApproving(true);
        router.post(
            manage.scores.approve(tournament.slug).url,
            {},
            {
                onError: (formErrors) => setErrors(formErrors),
                onFinish: () => setApproving(false),
            },
        );
    };

    return (
        <>
            <Head title={`${t('Review scores')} · ${t(tournament.name)}`} />
            <div className="flex h-full flex-1 flex-col gap-6 p-4 sm:p-6 lg:p-8">
                <header className="hero relative overflow-hidden rounded-3xl border border-border p-8">
                    <div className="hero-lines" />
                    <div className="relative flex flex-wrap items-end justify-between gap-4">
                        <div className="flex flex-col gap-3">
                            <span className="inline-flex items-center gap-2 text-xs font-bold tracking-[0.14em] text-muted-foreground uppercase">
                                <ClipboardCheck className="size-4 text-primary" />
                                {t('Score review')}
                            </span>
                            <h1 className="text-3xl font-semibold tracking-tight text-foreground sm:text-4xl">
                                {t(tournament.name)}
                            </h1>
                            <p className="max-w-xl text-sm text-muted-foreground">
                                {t(
                                    "Enter or correct each final score, set the advancing team for knockout matches, then approve to publish results and update everyone's points.",
                                )}
                            </p>
                        </div>
                        <Button onClick={approve} disabled={approving}>
                            <Trophy className="size-4" />
                            {approving
                                ? t('Approving…')
                                : t('Approve & publish')}
                        </Button>
                    </div>
                </header>

                {(errors.proposals || errors.batch || errors.ties) && (
                    <div className="rounded-2xl border border-destructive/30 bg-destructive/[0.06] p-4 text-sm font-medium text-destructive">
                        {errors.proposals ?? errors.batch ?? errors.ties}
                    </div>
                )}

                <TieResolutionSection
                    slug={tournament.slug}
                    tiedGroups={tiedGroups}
                    thirdsTie={thirdsTie}
                />

                {rows.length > 0 ? (
                    <div className="overflow-hidden rounded-3xl border border-border bg-card shadow-[var(--sh-sm)]">
                        {rows.map((row) => (
                            <ReviewRow
                                key={row.fixture_id}
                                row={row}
                                slug={tournament.slug}
                            />
                        ))}
                    </div>
                ) : (
                    <div className="flex min-h-44 flex-col items-center justify-center gap-2 rounded-3xl border border-dashed border-border p-8 text-center">
                        <ClipboardCheck className="size-6 text-muted-foreground" />
                        <p className="font-display font-semibold">
                            {t('Nothing to review')}
                        </p>
                        <p className="max-w-sm text-sm text-muted-foreground">
                            {t(
                                'No finished matches are waiting for a score right now. Matches appear here once they have ended.',
                            )}
                        </p>
                    </div>
                )}
            </div>
        </>
    );
}

const breadcrumbs: BreadcrumbItem[] = [
    { title: 'Manage', href: manage.index() },
];

ScoreReview.layout = { breadcrumbs };
