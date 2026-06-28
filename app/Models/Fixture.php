<?php

namespace App\Models;

use App\Enums\BatchStatus;
use App\Enums\FeederOutcome;
use App\Enums\FixtureStatus;
use App\Enums\LiveStatus;
use App\Enums\PhaseType;
use Carbon\CarbonInterface;
use Database\Factories\FixtureFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;
use RuntimeException;

#[Fillable([
    'tournament_id',
    'phase_id',
    'group_id',
    'match_number',
    'bracket_slot',
    'home_team_id',
    'away_team_id',
    'home_placeholder_label',
    'away_placeholder_label',
    'home_feeder_fixture_id',
    'away_feeder_fixture_id',
    'home_feeder_outcome',
    'away_feeder_outcome',
    'home_goals',
    'away_goals',
    'home_penalties',
    'away_penalties',
    'winner_team_id',
    'kicks_off_at',
    'venue',
    'venue_timezone',
    'status',
])]
class Fixture extends Model
{
    /** @use HasFactory<FixtureFactory> */
    use HasFactory;

    protected $table = 'fixtures';

    /**
     * Get the attributes that should be cast.
     *
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'home_feeder_outcome' => FeederOutcome::class,
            'away_feeder_outcome' => FeederOutcome::class,
            'home_goals' => 'integer',
            'away_goals' => 'integer',
            'home_penalties' => 'integer',
            'away_penalties' => 'integer',
            'kicks_off_at' => 'datetime',
            'status' => FixtureStatus::class,
        ];
    }

    /**
     * @return BelongsTo<Tournament, $this>
     */
    public function tournament(): BelongsTo
    {
        return $this->belongsTo(Tournament::class);
    }

    /**
     * @return BelongsTo<Phase, $this>
     */
    public function phase(): BelongsTo
    {
        return $this->belongsTo(Phase::class);
    }

    /**
     * @return BelongsTo<Group, $this>
     */
    public function group(): BelongsTo
    {
        return $this->belongsTo(Group::class);
    }

    /**
     * @return BelongsTo<Team, $this>
     */
    public function homeTeam(): BelongsTo
    {
        return $this->belongsTo(Team::class, 'home_team_id');
    }

    /**
     * @return BelongsTo<Team, $this>
     */
    public function awayTeam(): BelongsTo
    {
        return $this->belongsTo(Team::class, 'away_team_id');
    }

    /**
     * @return BelongsTo<Team, $this>
     */
    public function winner(): BelongsTo
    {
        return $this->belongsTo(Team::class, 'winner_team_id');
    }

    /**
     * @return BelongsTo<Fixture, $this>
     */
    public function homeFeeder(): BelongsTo
    {
        return $this->belongsTo(Fixture::class, 'home_feeder_fixture_id');
    }

    /**
     * @return BelongsTo<Fixture, $this>
     */
    public function awayFeeder(): BelongsTo
    {
        return $this->belongsTo(Fixture::class, 'away_feeder_fixture_id');
    }

    /**
     * @return HasMany<GroupPrediction, $this>
     */
    public function groupPredictions(): HasMany
    {
        return $this->hasMany(GroupPrediction::class);
    }

    /**
     * @return HasMany<KnockoutPrediction, $this>
     */
    public function knockoutPredictions(): HasMany
    {
        return $this->hasMany(KnockoutPrediction::class);
    }

    /**
     * @return HasMany<ScoreProposal, $this>
     */
    public function scoreProposals(): HasMany
    {
        return $this->hasMany(ScoreProposal::class);
    }

    /**
     * The live scoreboard for this fixture (created when an admin marks it live), held entirely
     * apart from the official result columns.
     *
     * @return HasOne<FixtureLiveState, $this>
     */
    public function liveState(): HasOne
    {
        return $this->hasOne(FixtureLiveState::class);
    }

    /**
     * Limit the query to fixtures with an in-progress live scoreboard (the Live Center set).
     *
     * @param  Builder<Fixture>  $query
     */
    public function scopeHasLiveState(Builder $query): void
    {
        $query->whereHas('liveState', fn (Builder $state) => $state->where('status', LiveStatus::Live));
    }

    /**
     * Whether an admin may mark this fixture live now. Only a scheduled match qualifies, and only
     * once it is within the go-live buffer of kickoff (or already past kickoff). This is the gate
     * that replaces the old time-based auto-advance — going live is admin-driven.
     *
     * @see config('scoring.go_live_buffer_minutes')
     */
    public function canGoLive(): bool
    {
        if ($this->status !== FixtureStatus::Scheduled || $this->kicks_off_at === null) {
            return false;
        }

        return now()->gte($this->kicks_off_at->subMinutes($this->goLiveBufferMinutes()));
    }

    /**
     * Move this fixture to a new kickoff and venue. Only a not-yet-finished match can move: its
     * result is not yet official, so shifting it is safe. A live match reverts to Scheduled (it
     * will now happen in the future), and any pending proposed result for it in an open,
     * unapproved batch is discarded — that proposal describes a match that was not actually played
     * to completion, so it must not survive the move.
     *
     * @throws RuntimeException when the fixture is already Finished.
     */
    public function reschedule(CarbonInterface $kickoff, string $venue, string $venueTimezone): void
    {
        if ($this->status === FixtureStatus::Finished) {
            throw new RuntimeException('A finished fixture cannot be rescheduled.');
        }

        $this->scoreProposals()
            ->whereRelation('batch', 'status', BatchStatus::Open)
            ->delete();

        // Drop any live scoreboard: a rescheduled match will be played afresh in the future, so it
        // must leave the Live Center (a new live state is created if it goes live again).
        $this->liveState()->delete();

        $this->update([
            'kicks_off_at' => $kickoff,
            'venue' => $venue,
            'venue_timezone' => $venueTimezone,
            'status' => FixtureStatus::Scheduled,
        ]);
    }

    public function isGroup(): bool
    {
        return $this->phase?->type === PhaseType::Group;
    }

    public function isKnockout(): bool
    {
        return $this->phase?->type === PhaseType::Knockout;
    }

    /**
     * Whether the match has reached its scheduled kickoff.
     */
    public function hasKickedOff(): bool
    {
        return $this->kicks_off_at !== null && now()->gte($this->kicks_off_at);
    }

    /**
     * The instant this match's own prediction window closes. Rolling Predictions pools lock each
     * match at its kickoff — the prediction is the only one that gates per fixture, so the cutoff
     * is simply {@see $kicks_off_at} (no buffer). Null when no kickoff is scheduled, so callers
     * fail closed. This is strategy-agnostic; only a {@see Pool::usesPerMatchPredictionWindows()}
     * pool consults it, via {@see PredictionWindowResolver::statusForFixture()}.
     */
    public function predictionsLockAt(): ?CarbonInterface
    {
        return $this->kicks_off_at;
    }

    /**
     * Whether this match still accepts prediction edits — true only before its kickoff. A match
     * with no scheduled kickoff is treated as closed (fail closed).
     */
    public function acceptsPredictions(): bool
    {
        $lock = $this->predictionsLockAt();

        return $lock !== null && now()->lessThan($lock);
    }

    /**
     * Whether the match is over and therefore eligible for an official score.
     *
     * An admin who ended the live match (live scoreboard {@see LiveStatus::Ended})
     * has declared it over — the authoritative "match over" signal, honoured
     * immediately regardless of the clock. A match no one tracked live falls back
     * to a time-based heuristic: it is live and enough time has passed since
     * kickoff to cover regulation, extra time and penalties (see
     * config('scoring.match_duration_minutes')). This is the single gate both
     * the admin review screen and the scheduled fetch honour — a score can
     * only ever be entered for a match that is truly finished.
     */
    public function hasEnded(): bool
    {
        if ($this->liveState?->isEnded() === true) {
            return true;
        }

        return $this->status === FixtureStatus::Live
            && $this->kicks_off_at !== null
            && now()->gte($this->kicks_off_at->addMinutes($this->matchDurationMinutes()));
    }

    /**
     * Limit the query to fixtures that have ended — mirrors {@see hasEnded()}: a live
     * scoreboard explicitly ended by the admin, or live and past full time.
     *
     * @param  Builder<Fixture>  $query
     */
    public function scopeEnded(Builder $query): void
    {
        $query->where(function (Builder $query): void {
            $query->whereHas('liveState', fn (Builder $state) => $state->where('status', LiveStatus::Ended))
                ->orWhere(function (Builder $query): void {
                    $query->where('status', FixtureStatus::Live)
                        ->whereNotNull('kicks_off_at')
                        ->where('kicks_off_at', '<=', now()->subMinutes($this->matchDurationMinutes()));
                });
        });
    }

    private function matchDurationMinutes(): int
    {
        return (int) config('scoring.match_duration_minutes');
    }

    private function goLiveBufferMinutes(): int
    {
        return (int) config('scoring.go_live_buffer_minutes');
    }
}
