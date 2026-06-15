<?php

namespace App\Services\Predictions\Import;

use App\Models\GroupPrediction;

/**
 * A backfill JSON blob after parsing and mapping to the tournament: every match (resolved to a
 * fixture/teams where possible), the third-place classification team ids in the order pasted, and
 * the diagnostics — team codes and match numbers that matched nothing — that drive the review
 * banner. Holds no state about the destination entry; it is the same for any user.
 */
final class ParsedImport
{
    /**
     * @param  list<ParsedMatch>  $matches
     * @param  list<int>  $thirdsTeamIds  resolved third-place team ids, in pasted order
     * @param  list<string>  $unknownTeamCodes  team codes present in the blob that matched no team
     * @param  list<int>  $unknownMatchNumbers  match numbers present in the blob that matched no fixture
     * @param  array<string, list<int>>  $groupStandings  uppercased group label => resolved team ids in
     *                                                    the user's stated finishing order, used only to
     *                                                    break a genuine score-tie inside that group
     */
    public function __construct(
        public readonly array $matches,
        public readonly array $thirdsTeamIds,
        public readonly array $unknownTeamCodes,
        public readonly array $unknownMatchNumbers,
        public readonly array $groupStandings = [],
    ) {}

    /**
     * Group-stage rows ready to write as {@see GroupPrediction}: only matches mapped to
     * a fixture and carrying both goals (a group prediction exists only where a score was entered).
     *
     * @return list<array{fixture_id: int, home_goals: int, away_goals: int}>
     */
    public function groupRows(): array
    {
        $rows = [];

        foreach ($this->matches as $match) {
            if ($match->isGroup() && $match->fixtureId !== null && $match->hasBothGoals()) {
                $rows[] = [
                    'fixture_id' => $match->fixtureId,
                    'home_goals' => $match->homeGoals,
                    'away_goals' => $match->awayGoals,
                ];
            }
        }

        return $rows;
    }

    /**
     * Knockout rows to stamp onto the derived bracket: the JSON score and the "advances" pick (only
     * honoured on a draw — a decisive score derives the advancing team server-side).
     *
     * @return list<array{fixture_id: int, home_goals: int|null, away_goals: int|null, advancing_pick: int|null}>
     */
    public function knockoutRows(): array
    {
        $rows = [];

        foreach ($this->matches as $match) {
            if ($match->isKnockout && $match->fixtureId !== null) {
                $rows[] = [
                    'fixture_id' => $match->fixtureId,
                    'home_goals' => $match->homeGoals,
                    'away_goals' => $match->awayGoals,
                    'advancing_pick' => $match->advancesTeamId,
                ];
            }
        }

        return $rows;
    }

    /**
     * Knockout rows carrying the JSON's OWN authored participants (not a derived slot), for an
     * "import the bracket as authored" upfront commit: the team the author typed in each spot plus
     * the score and "advances" pick. Resolved team ids may be null where a code matched no team.
     *
     * @return list<array{fixture_id: int, predicted_home_team_id: int|null, predicted_away_team_id: int|null, home_goals: int|null, away_goals: int|null, advancing_pick: int|null}>
     */
    public function literalKnockoutRows(): array
    {
        $rows = [];

        foreach ($this->matches as $match) {
            if ($match->isKnockout && $match->fixtureId !== null) {
                $rows[] = [
                    'fixture_id' => $match->fixtureId,
                    'predicted_home_team_id' => $match->homeTeamId,
                    'predicted_away_team_id' => $match->awayTeamId,
                    'home_goals' => $match->homeGoals,
                    'away_goals' => $match->awayGoals,
                    'advancing_pick' => $match->advancesTeamId,
                ];
            }
        }

        return $rows;
    }
}
