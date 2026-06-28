<?php

namespace App\Http\Controllers;

use App\Enums\FixtureStatus;
use App\Enums\PhaseType;
use App\Enums\ScoringStrategy;
use App\Http\Requests\Tournaments\RescheduleFixtureRequest;
use App\Models\Fixture;
use App\Models\Team;
use App\Models\Tournament;
use Illuminate\Http\RedirectResponse;
use Inertia\Inertia;
use Inertia\Response;

class FixtureScheduleController extends Controller
{
    /**
     * The admin screen for rescheduling a tournament's not-yet-finished fixtures (kickoff + venue).
     */
    public function index(Tournament $tournament): Response
    {
        $venues = $tournament->venueTimezones();
        $governing = $this->lockGoverningFixtureIds($tournament);

        $fixtures = $tournament->fixtures()
            ->with(['phase', 'homeTeam', 'awayTeam'])
            ->orderBy('match_number')
            ->get();

        return Inertia::render('manage/schedule', [
            'tournament' => $this->tournamentIdentity($tournament),
            'venues' => collect($venues)
                ->map(fn (string $timezone, string $name): array => ['name' => $name, 'timezone' => $timezone])
                ->values()
                ->all(),
            'rows' => $fixtures->map(fn (Fixture $fixture): array => [
                'id' => $fixture->id,
                'match_number' => $fixture->match_number,
                'phase' => $fixture->phase->name,
                'is_knockout' => $fixture->isKnockout(),
                'status' => $fixture->status->value,
                'kicks_off_at' => $fixture->kicks_off_at?->toIso8601String(),
                'venue' => $fixture->venue,
                'venue_timezone' => $fixture->venue_timezone,
                'home' => $this->teamRef($fixture->homeTeam),
                'away' => $this->teamRef($fixture->awayTeam),
                'home_label' => $fixture->homeTeam?->name ?? $fixture->home_placeholder_label,
                'away_label' => $fixture->awayTeam?->name ?? $fixture->away_placeholder_label,
                'governs_prediction_lock' => in_array($fixture->id, $governing, true),
            ])->values()->all(),
        ]);
    }

    /**
     * Move a not-yet-finished fixture to a new kickoff and venue, discarding any pending proposed
     * result for it.
     */
    public function reschedule(RescheduleFixtureRequest $request, Tournament $tournament, Fixture $fixture): RedirectResponse
    {
        abort_unless($fixture->tournament_id === $tournament->id, 404);

        $fixture->reschedule($request->newKickoff(), $request->venue(), $request->venueTimezone());

        // Moving a fixture out of "live" can change the lifecycle status — e.g. rescheduling the
        // only kicked-off match into the future reverts the tournament to Upcoming.
        $tournament->syncStatus();

        Inertia::flash('toast', ['type' => 'success', 'message' => __('Fixture rescheduled.')]);

        return to_route('manage.schedule.index', $tournament);
    }

    /**
     * The ids of fixtures whose kickoff currently governs a derived prediction lock, so the admin
     * can be warned before moving one. The earliest group fixture sets every pool's group-stage
     * lock; for a tournament with any phased-bracket pool, the earliest fixture of each knockout
     * round sets that round's lock.
     *
     * @return list<int>
     */
    private function lockGoverningFixtureIds(Tournament $tournament): array
    {
        $ids = [];

        $earliestGroup = $tournament->groupFixtures()
            ->whereNotNull('kicks_off_at')
            ->orderBy('kicks_off_at')
            ->orderBy('id')
            ->value('id');

        if ($earliestGroup !== null) {
            $ids[] = (int) $earliestGroup;
        }

        $hasPhasedPool = $tournament->pools()
            ->where('scoring_strategy', ScoringStrategy::PhasedBracket->value)
            ->exists();

        if ($hasPhasedPool) {
            $knockoutPhaseIds = $tournament->phases()->where('type', PhaseType::Knockout->value)->pluck('id');

            foreach ($knockoutPhaseIds as $phaseId) {
                $earliest = $tournament->fixtures()
                    ->where('phase_id', $phaseId)
                    ->whereNotNull('kicks_off_at')
                    ->orderBy('kicks_off_at')
                    ->orderBy('id')
                    ->value('id');

                if ($earliest !== null) {
                    $ids[] = (int) $earliest;
                }
            }
        }

        // A rolling (per-match) pool locks every match at its own kickoff, so rescheduling ANY
        // not-yet-finished fixture moves that match's prediction window — every one governs a lock.
        $hasRollingPool = $tournament->pools()
            ->where('scoring_strategy', ScoringStrategy::RollingBracket->value)
            ->exists();

        if ($hasRollingPool) {
            $ids = array_merge($ids, $tournament->fixtures()
                ->where('status', '!=', FixtureStatus::Finished->value)
                ->pluck('id')
                ->map(fn ($id): int => (int) $id)
                ->all());
        }

        return array_values(array_unique($ids));
    }

    /**
     * @return array{name: string, slug: string, status: string}
     */
    private function tournamentIdentity(Tournament $tournament): array
    {
        return [
            'name' => $tournament->name,
            'slug' => $tournament->slug,
            'status' => $tournament->status->value,
        ];
    }

    /**
     * @return array{id: int, name: string, code: ?string, flag_url: string}|null
     */
    private function teamRef(?Team $team): ?array
    {
        if ($team === null) {
            return null;
        }

        return [
            'id' => $team->id,
            'name' => $team->name,
            'code' => $team->code,
            'flag_url' => $team->flag_url,
        ];
    }
}
