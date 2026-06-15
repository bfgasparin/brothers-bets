import { Head, Link, router } from '@inertiajs/react';
import { AlertTriangle, ArrowLeft, Trophy } from 'lucide-react';
import { useState } from 'react';
import { Flag } from '@/components/flag';
import { ScoreStepper } from '@/components/score-stepper';
import { TeamScoreRow } from '@/components/team-score-row';
import { Button } from '@/components/ui/button';
import { Checkbox } from '@/components/ui/checkbox';
import { ToggleGroup, ToggleGroupItem } from '@/components/ui/toggle-group';
import { useTranslation } from '@/hooks/use-translation';
import { cn } from '@/lib/utils';
import manage from '@/routes/manage';
import type { BreadcrumbItem } from '@/types/navigation';
import type { TeamRef } from '@/types/pools';

interface RowData {
    match_number: number;
    fixture_id: number;
    phase: string | null;
    is_knockout: boolean;
    home: TeamRef | null;
    away: TeamRef | null;
    json_home: TeamRef | null;
    json_away: TeamRef | null;
    home_goals: number | null;
    away_goals: number | null;
    advancing: TeamRef | null;
    json_advances: TeamRef | null;
    position_advance: { side: 'home' | 'away'; team: TeamRef } | null;
    flags: string[];
    severity: 'ok' | 'warning' | 'error';
}

interface GroupTie {
    group: string;
    resolved_by_standings: boolean;
    teams: TeamRef[];
}

interface PreviewData {
    rows: RowData[];
    banner: {
        unknown_match_numbers: number[];
        unknown_team_codes: string[];
        missing_match_numbers: number[];
        already_populated: boolean;
        thirds_mismatch: boolean;
    };
    thirds: { json: TeamRef[]; derived: TeamRef[] };
    counts: { group: number; knockout: number };
    group_ties: GroupTie[];
    has_errors: boolean;
}

interface ReviewProps {
    tournament: { name: string; slug: string };
    pool: { id: number; name: string; source: string; slug: string };
    user: { id: number; name: string; email: string | null };
    preview: PreviewData;
    thirds_team_ids: number[];
    group_standings_team_ids: Record<string, number[]>;
}

type RowValue = { home: string; away: string; advancing: string };

const FLAG_META: Record<string, { label: string; tone: 'error' | 'warning' }> =
    {
        score_missing: { label: 'No score entered', tone: 'warning' },
        matchup_mismatch: {
            label: 'Doesn’t match the player’s group-stage picks',
            tone: 'warning',
        },
        knockout_unreachable: {
            label: 'Not reached by these group-stage picks',
            tone: 'warning',
        },
        advances_not_in_match: {
            label: 'Advancing team isn’t in this match',
            tone: 'error',
        },
        advances_contradicts_score: {
            label: 'Score decides who advances — pick ignored',
            tone: 'warning',
        },
        advances_missing_on_draw: {
            label: 'Choose who advances on penalties',
            tone: 'warning',
        },
    };

function toNumberOrNull(value: string): number | null {
    return value === '' ? null : Number(value);
}

function FlagChip({ flag }: { flag: string }) {
    const { t } = useTranslation();
    const meta = FLAG_META[flag];

    if (!meta) {
        return null;
    }

    return (
        <span
            className={cn(
                'inline-flex items-center rounded-full px-2 py-0.5 text-[10px] font-bold tracking-wide uppercase',
                meta.tone === 'error'
                    ? 'bg-destructive/[0.12] text-destructive'
                    : 'bg-accent/15 text-[#8a5a00] dark:text-amber-300',
            )}
        >
            {t(meta.label)}
        </span>
    );
}

function ReviewRow({
    row,
    value,
    onChange,
}: {
    row: RowData;
    value: RowValue;
    onChange: (patch: Partial<RowValue>) => void;
}) {
    const { t, tCountry } = useTranslation();

    const teamsKnown = row.home !== null && row.away !== null;
    const bothScored = value.home !== '' && value.away !== '';
    const isDraw = bothScored && Number(value.home) === Number(value.away);
    const decisiveWinner =
        bothScored && !isDraw && teamsKnown
            ? Number(value.home) > Number(value.away)
                ? row.home
                : row.away
            : null;

    const handleScore = (side: 'home' | 'away', next: string): void => {
        const nextHome = side === 'home' ? next : value.home;
        const nextAway = side === 'away' ? next : value.away;
        const patch: Partial<RowValue> = { [side]: next };

        if (row.is_knockout && teamsKnown) {
            if (nextHome === '' || nextAway === '') {
                patch.advancing = '';
            } else if (Number(nextHome) > Number(nextAway)) {
                patch.advancing = String(row.home!.id);
            } else if (Number(nextAway) > Number(nextHome)) {
                patch.advancing = String(row.away!.id);
            }
        }

        onChange(patch);
    };

    return (
        <div className="flex flex-col gap-3 border-b border-border p-4 last:border-0">
            <div className="flex flex-wrap items-center gap-2">
                <span className="font-display text-sm font-semibold">
                    {t('Match :number', { number: row.match_number })}
                </span>
                {row.phase && (
                    <span className="text-[11px] font-medium text-muted-foreground">
                        {t(row.phase)}
                    </span>
                )}
                {row.flags.map((flag) => (
                    <FlagChip key={flag} flag={flag} />
                ))}
            </div>

            {row.flags.includes('matchup_mismatch') && (
                <p className="text-xs text-muted-foreground">
                    {t('Player’s JSON listed :home vs :away.', {
                        home: row.json_home
                            ? tCountry(row.json_home.code, row.json_home.name)
                            : '—',
                        away: row.json_away
                            ? tCountry(row.json_away.code, row.json_away.name)
                            : '—',
                    })}
                </p>
            )}

            {row.position_advance && (
                <p className="text-xs text-muted-foreground">
                    {bothScored && !isDraw
                        ? t(
                              'Advances :team, who isn’t in this match. The score decides who advances.',
                              {
                                  team: row.json_advances
                                      ? tCountry(
                                            row.json_advances.code,
                                            row.json_advances.name,
                                        )
                                      : '—',
                              },
                          )
                        : t(
                              'Advances :team, who isn’t in this match. :real will advance instead.',
                              {
                                  team: row.json_advances
                                      ? tCountry(
                                            row.json_advances.code,
                                            row.json_advances.name,
                                        )
                                      : '—',
                                  real: tCountry(
                                      row.position_advance.team.code,
                                      row.position_advance.team.name,
                                  ),
                              },
                          )}
                </p>
            )}

            <div>
                <TeamScoreRow team={row.home} label={t('Home')}>
                    <ScoreStepper
                        value={value.home}
                        onChange={(next) => handleScore('home', next)}
                        label={t(':team goals', {
                            team: row.home
                                ? tCountry(row.home.code, row.home.name)
                                : t('Home'),
                        })}
                    />
                </TeamScoreRow>
                <TeamScoreRow team={row.away} label={t('Away')}>
                    <ScoreStepper
                        value={value.away}
                        onChange={(next) => handleScore('away', next)}
                        label={t(':team goals', {
                            team: row.away
                                ? tCountry(row.away.code, row.away.name)
                                : t('Away'),
                        })}
                    />
                </TeamScoreRow>
            </div>

            {row.is_knockout && teamsKnown && bothScored && (
                <div className="flex flex-wrap items-center gap-2">
                    {isDraw ? (
                        <>
                            <span className="text-[0.65rem] font-semibold tracking-wide text-muted-foreground uppercase">
                                {t('Advances on penalties')}
                            </span>
                            <ToggleGroup
                                type="single"
                                variant="outline"
                                size="sm"
                                value={value.advancing}
                                onValueChange={(next) =>
                                    next && onChange({ advancing: next })
                                }
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
                            <Flag team={decisiveWinner} className="h-4 w-6" />
                            {decisiveWinner &&
                                tCountry(
                                    decisiveWinner.code,
                                    decisiveWinner.name,
                                )}
                        </span>
                    )}
                </div>
            )}
        </div>
    );
}

function Banner({ preview }: { preview: PreviewData }) {
    const { t } = useTranslation();
    const { banner } = preview;

    const hasUnknown =
        banner.unknown_match_numbers.length > 0 ||
        banner.unknown_team_codes.length > 0;

    if (
        !hasUnknown &&
        banner.missing_match_numbers.length === 0 &&
        !banner.already_populated &&
        !banner.thirds_mismatch
    ) {
        return null;
    }

    return (
        <div className="flex flex-col gap-2">
            {hasUnknown && (
                <div className="rounded-2xl border border-destructive/30 bg-destructive/[0.06] p-4 text-sm font-medium text-destructive">
                    <p className="flex items-center gap-2 font-semibold">
                        <AlertTriangle className="size-4" />
                        {t(
                            'Some entries didn’t match the tournament. Fix the JSON and paste again.',
                        )}
                    </p>
                    {banner.unknown_match_numbers.length > 0 && (
                        <p className="mt-1">
                            {t('Unknown match numbers: :list', {
                                list: banner.unknown_match_numbers.join(', '),
                            })}
                        </p>
                    )}
                    {banner.unknown_team_codes.length > 0 && (
                        <p className="mt-1">
                            {t('Unknown team codes: :list', {
                                list: banner.unknown_team_codes.join(', '),
                            })}
                        </p>
                    )}
                </div>
            )}

            {banner.missing_match_numbers.length > 0 && (
                <div className="rounded-2xl border border-amber/40 bg-accent/[0.07] p-4 text-sm text-foreground">
                    {t(':count group matches were not in the JSON.', {
                        count: banner.missing_match_numbers.length,
                    })}
                </div>
            )}

            {banner.thirds_mismatch && (
                <div className="rounded-2xl border border-amber/40 bg-accent/[0.07] p-4 text-sm text-foreground">
                    {t(
                        'The third-place ranking in the JSON differs from the one derived from the group scores.',
                    )}
                </div>
            )}
        </div>
    );
}

function GroupTiesNotice({ ties }: { ties: GroupTie[] }) {
    const { t, tCountry } = useTranslation();

    if (ties.length === 0) {
        return null;
    }

    return (
        <div className="rounded-2xl border border-border bg-card p-4 text-sm shadow-[var(--sh-sm)]">
            <p className="mb-2 font-semibold">{t('Group ties')}</p>
            <ul className="flex flex-col gap-2.5">
                {ties.map((tie) => (
                    <li
                        key={tie.group}
                        className="flex flex-wrap items-center gap-x-2 gap-y-1"
                    >
                        <span className="font-display font-semibold">
                            {t('Group :group', { group: tie.group })}
                        </span>
                        <span className="text-muted-foreground">
                            {tie.resolved_by_standings
                                ? t('ordered from the standings you pasted')
                                : t('no standings provided — kept seed order')}
                        </span>
                        <span className="inline-flex items-center gap-1.5">
                            {tie.teams.map((team, index) => (
                                <span
                                    key={team.id}
                                    className="inline-flex items-center gap-1"
                                >
                                    {index > 0 && (
                                        <span className="text-muted-foreground">
                                            ›
                                        </span>
                                    )}
                                    <Flag team={team} className="h-3.5 w-5" />
                                    <span className="text-xs font-medium">
                                        {tCountry(team.code, team.name)}
                                    </span>
                                </span>
                            ))}
                        </span>
                    </li>
                ))}
            </ul>
        </div>
    );
}

export default function BackfillReview({
    tournament,
    pool,
    user,
    preview,
    thirds_team_ids: thirdsTeamIds,
    group_standings_team_ids: groupStandingsTeamIds,
}: ReviewProps) {
    const { t } = useTranslation();

    const [values, setValues] = useState<Record<number, RowValue>>(() =>
        Object.fromEntries(
            preview.rows.map((row) => [
                row.fixture_id,
                {
                    home: row.home_goals?.toString() ?? '',
                    away: row.away_goals?.toString() ?? '',
                    advancing: row.advancing?.id?.toString() ?? '',
                },
            ]),
        ),
    );
    const [overwrite, setOverwrite] = useState(false);
    const [acceptPositionAdvance, setAcceptPositionAdvance] = useState(false);
    const [committing, setCommitting] = useState(false);
    const [errors, setErrors] = useState<Record<string, string>>({});

    const update = (fixtureId: number, patch: Partial<RowValue>): void => {
        setValues((current) => ({
            ...current,
            [fixtureId]: { ...current[fixtureId], ...patch },
        }));
    };

    // Knockout matches whose pasted advancing team isn't in the derived match-up. They block the import
    // unless the admin consents to the positional salvage (advance the real team on the same side);
    // a mismatch with no resolvable side (`position_advance === null`) can't be salvaged and keeps
    // blocking.
    const advanceMismatchRows = preview.rows.filter((row) =>
        row.flags.includes('advances_not_in_match'),
    );
    const salvageableRows = advanceMismatchRows.filter(
        (row) => row.position_advance !== null,
    );

    // On consent, fill the proposed positional team where the row has no advancing pick yet (drawn
    // matches — a decisive score already advances the right team on its own).
    const acceptPosition = (checked: boolean): void => {
        setAcceptPositionAdvance(checked);

        if (!checked) {
            return;
        }

        setValues((current) => {
            const next = { ...current };

            for (const row of salvageableRows) {
                if (
                    row.position_advance &&
                    next[row.fixture_id].advancing === ''
                ) {
                    next[row.fixture_id] = {
                        ...next[row.fixture_id],
                        advancing: String(row.position_advance.team.id),
                    };
                }
            }

            return next;
        });
    };

    const otherBlocking =
        preview.banner.unknown_match_numbers.length > 0 ||
        preview.banner.unknown_team_codes.length > 0 ||
        advanceMismatchRows.length > salvageableRows.length;
    const blockedByOverwrite = preview.banner.already_populated && !overwrite;
    const canCommit =
        !otherBlocking &&
        (salvageableRows.length === 0 || acceptPositionAdvance) &&
        !blockedByOverwrite &&
        !committing;

    const commit = () => {
        const group: Array<{
            fixture_id: number;
            home_goals: number;
            away_goals: number;
        }> = [];
        const knockout: Array<{
            fixture_id: number;
            home_goals: number | null;
            away_goals: number | null;
            advancing_team_id: number | null;
        }> = [];

        for (const row of preview.rows) {
            const value = values[row.fixture_id];

            if (!row.is_knockout) {
                if (value.home !== '' && value.away !== '') {
                    group.push({
                        fixture_id: row.fixture_id,
                        home_goals: Number(value.home),
                        away_goals: Number(value.away),
                    });
                }

                continue;
            }

            knockout.push({
                fixture_id: row.fixture_id,
                home_goals: toNumberOrNull(value.home),
                away_goals: toNumberOrNull(value.away),
                advancing_team_id: toNumberOrNull(value.advancing),
            });
        }

        setCommitting(true);
        setErrors({});
        router.post(
            manage.backfill.commit(tournament.slug).url,
            {
                pool_id: pool.id,
                user_id: user.id,
                overwrite,
                group,
                knockout,
                thirds_team_ids: thirdsTeamIds,
                group_standings_team_ids: groupStandingsTeamIds,
            },
            {
                onError: (formErrors) => setErrors(formErrors),
                onFinish: () => setCommitting(false),
            },
        );
    };

    return (
        <>
            <Head title={`${t('Review import')} · ${user.name}`} />
            <div className="flex h-full flex-1 flex-col gap-6 p-4 sm:p-6 lg:p-8">
                <header className="hero relative overflow-hidden rounded-3xl border border-border p-8">
                    <div className="hero-lines" />
                    <div className="relative flex flex-wrap items-end justify-between gap-4">
                        <div className="flex flex-col gap-2">
                            <span className="text-xs font-bold tracking-[0.14em] text-muted-foreground uppercase">
                                {t('Review import')}
                            </span>
                            <h1 className="text-3xl font-semibold tracking-tight text-foreground sm:text-4xl">
                                {user.name}
                            </h1>
                            <p className="max-w-xl text-sm text-muted-foreground">
                                {pool.name} · {pool.source} ·{' '}
                                {t(
                                    ':group group · :knockout knockout matches',
                                    {
                                        group: preview.counts.group,
                                        knockout: preview.counts.knockout,
                                    },
                                )}
                            </p>
                        </div>
                        <div className="flex items-center gap-2">
                            <Button variant="ghost" asChild>
                                <Link
                                    href={
                                        manage.backfill.create(tournament.slug)
                                            .url
                                    }
                                >
                                    <ArrowLeft className="size-4" />
                                    {t('Back')}
                                </Link>
                            </Button>
                            <Button onClick={commit} disabled={!canCommit}>
                                <Trophy className="size-4" />
                                {committing
                                    ? t('Importing…')
                                    : t('Commit & re-score')}
                            </Button>
                        </div>
                    </div>
                </header>

                {errors.overwrite && (
                    <div className="rounded-2xl border border-destructive/30 bg-destructive/[0.06] p-4 text-sm font-medium text-destructive">
                        {errors.overwrite}
                    </div>
                )}

                <Banner preview={preview} />

                <GroupTiesNotice ties={preview.group_ties} />

                {preview.banner.already_populated && (
                    <label className="flex items-start gap-3 rounded-2xl border border-amber/40 bg-accent/[0.07] p-4 text-sm">
                        <Checkbox
                            checked={overwrite}
                            onCheckedChange={(checked) =>
                                setOverwrite(checked === true)
                            }
                            className="mt-0.5"
                        />
                        <span>
                            <span className="font-semibold">
                                {t(':name already has predictions here.', {
                                    name: user.name,
                                })}
                            </span>{' '}
                            {t(
                                'Tick to replace them with this import. This cannot be undone.',
                            )}
                        </span>
                    </label>
                )}

                {salvageableRows.length > 0 && (
                    <label className="flex items-start gap-3 rounded-2xl border border-amber/40 bg-accent/[0.07] p-4 text-sm">
                        <Checkbox
                            checked={acceptPositionAdvance}
                            onCheckedChange={(checked) =>
                                acceptPosition(checked === true)
                            }
                            className="mt-0.5"
                        />
                        <span>
                            <span className="font-semibold">
                                {t(
                                    ':count knockout matches advance a team that isn’t in the derived match-up.',
                                    { count: salvageableRows.length },
                                )}
                            </span>{' '}
                            {t(
                                'Import anyway — advance the real team on the side the player picked (home or away).',
                            )}
                        </span>
                    </label>
                )}

                <div className="overflow-hidden rounded-3xl border border-border bg-card shadow-[var(--sh-sm)]">
                    {preview.rows.map((row) => (
                        <ReviewRow
                            key={row.fixture_id}
                            row={row}
                            value={values[row.fixture_id]}
                            onChange={(patch) => update(row.fixture_id, patch)}
                        />
                    ))}
                </div>
            </div>
        </>
    );
}

const breadcrumbs: BreadcrumbItem[] = [
    { title: 'Manage', href: manage.index() },
];

BackfillReview.layout = { breadcrumbs };
