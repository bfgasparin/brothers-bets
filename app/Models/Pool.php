<?php

namespace App\Models;

use App\Enums\PoolAccent;
use App\Enums\ScoringStrategy;
use App\Enums\TournamentStatus;
use Carbon\CarbonInterface;
use Database\Factories\PoolFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;

#[Fillable([
    'tournament_id',
    'slug',
    'name',
    'source',
    'accent',
    'scoring_strategy',
    'scoring_config',
    'predictions_lock_at',
    'entry_price',
    'currency',
    'house_fee_percentage',
    'prize_structure',
])]
class Pool extends Model
{
    /** @use HasFactory<PoolFactory> */
    use HasFactory;

    /** Memoised result of {@see predictionsLockAt()}; null is a valid resolved value. */
    private ?CarbonInterface $resolvedLock = null;

    private bool $lockResolved = false;

    /**
     * Get the attributes that should be cast.
     *
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'accent' => PoolAccent::class,
            'scoring_strategy' => ScoringStrategy::class,
            'scoring_config' => 'array',
            'predictions_lock_at' => 'datetime',
            'entry_price' => 'decimal:2',
            'house_fee_percentage' => 'decimal:2',
            'prize_structure' => 'array',
        ];
    }

    /**
     * The instant the group-stage prediction window closes. Defaults to the configured buffer
     * before the tournament's first group kickoff {@see Tournament::firstGroupKickoffAt()}; an
     * explicit {@see $predictions_lock_at} override (e.g. set by an admin) wins verbatim and
     * ignores the buffer. Null — no override and no scheduled kickoff —
     * means the window is closed (fail closed). Memoised per instance: the derivation runs one
     * aggregate query and {@see acceptsPredictions()} is called repeatedly within a request.
     */
    public function predictionsLockAt(): ?CarbonInterface
    {
        if (! $this->lockResolved) {
            $this->resolvedLock = $this->predictions_lock_at
                ?? $this->tournament->firstGroupKickoffAt()
                    ?->subMinutes((int) config('scoring.prediction_lock_buffer_minutes'));
            $this->lockResolved = true;
        }

        return $this->resolvedLock;
    }

    /**
     * Whether the pool still accepts prediction edits. This is driven by the prediction
     * window alone and is intentionally independent of the underlying tournament's lifecycle
     * {@see TournamentStatus}, which describes where the competition is in its life.
     */
    public function acceptsPredictions(): bool
    {
        $lock = $this->predictionsLockAt();

        return $lock !== null && now()->lessThan($lock);
    }

    /**
     * Whether a player can still join (enter) the pool. Joining follows the prediction window: for
     * a single-lock pool that is {@see acceptsPredictions()} (before the group-stage lock); a
     * per-match (rolling) pool stays joinable as long as any match is still open to predict, so a
     * late joiner can still call the remaining games.
     */
    public function acceptsJoins(): bool
    {
        if ($this->usesPerMatchPredictionWindows()) {
            return $this->tournament->fixtures()->where('kicks_off_at', '>', now())->exists();
        }

        return $this->acceptsPredictions();
    }

    /**
     * Whether players predict the whole knockout bracket upfront (teams included), as opposed to
     * a future between-phases model where knockout teams are known before they are predicted.
     * When true, a player's predicted knockout teams are worth surfacing so the per-team
     * placement points (10 per team correctly placed in a match, +5 goal-count bonus) can be
     * audited; otherwise the predicted teams would just equal the official ones.
     */
    public function predictsKnockoutBracket(): bool
    {
        return $this->scoring_strategy === ScoringStrategy::UpfrontBracket;
    }

    /**
     * Whether predictions lock per phase (a fresh window opening for each knockout round as its
     * real participants become known, closing the configured buffer before that round's first
     * kickoff), as opposed to a single group-stage lock {@see predictionsLockAt()} that gates the
     * whole pool at once. Drives {@see PredictionWindowResolver}.
     */
    public function usesPhasedPredictionWindows(): bool
    {
        return $this->scoring_strategy === ScoringStrategy::PhasedBracket;
    }

    /**
     * Whether predictions lock per individual fixture (Rolling Predictions): every match stays open
     * until its own kickoff, rather than the whole pool ({@see UpfrontBracket}) or a whole knockout
     * round ({@see PhasedBracket}) locking together. This is the "lock per fixture" lever, kept
     * distinct from {@see predictsOfficialBracket()} (the "where do knockout teams come from" lever),
     * which rolling shares with phased.
     */
    public function usesPerMatchPredictionWindows(): bool
    {
        return $this->scoring_strategy === ScoringStrategy::RollingBracket;
    }

    /**
     * Whether knockout participants come from the official results — filled onto the fixtures by
     * {@see OfficialBracketProjector} as rounds complete — rather than being self-derived from the
     * player's own group scores ({@see predictsKnockoutBracket()}). True for both the phased and
     * rolling strategies: players predict the real match-ups, with no cascade and no thirds ordering.
     */
    public function predictsOfficialBracket(): bool
    {
        return ! $this->predictsKnockoutBracket();
    }

    /**
     * The competition this pool is played over. The tournament owns the shared structure
     * (phases, groups, fixtures) and official results; the pool owns the scoring and entries.
     *
     * @return BelongsTo<Tournament, $this>
     */
    public function tournament(): BelongsTo
    {
        return $this->belongsTo(Tournament::class);
    }

    /**
     * @return HasMany<Entry, $this>
     */
    public function entries(): HasMany
    {
        return $this->hasMany(Entry::class);
    }

    /**
     * The user's entry in this pool, or null if they haven't joined. {@see User} has no
     * `entries()` relation, so participation is always reached from the pool side.
     */
    public function entryFor(?User $user): ?Entry
    {
        if ($user === null) {
            return null;
        }

        return $this->entries()->where('user_id', $user->id)->first();
    }

    /**
     * Whether the user has joined this pool (an entry exists). Joining is the prerequisite for
     * making predictions and for seeing the prediction-lock countdown.
     */
    public function isJoinedBy(?User $user): bool
    {
        return $this->entryFor($user) !== null;
    }

    /**
     * Users who have already opened this pool's "how it works" briefing. Tracked separately from
     * {@see entries()} so the one-time briefing fires for viewers who haven't joined yet.
     *
     * @return BelongsToMany<User, $this>
     */
    public function briefedUsers(): BelongsToMany
    {
        return $this->belongsToMany(User::class, 'pool_briefing_views')->withTimestamps();
    }

    /**
     * Whether the user has already seen this pool's briefing, so it shouldn't auto-open again.
     */
    public function briefingSeenBy(?User $user): bool
    {
        if ($user === null) {
            return false;
        }

        return $this->briefedUsers()->whereKey($user->id)->exists();
    }

    /**
     * Record that the user has now seen this pool's briefing. Idempotent — backed by the unique
     * (pool_id, user_id) index, so a repeat call leaves a single row.
     */
    public function markBriefingSeenBy(User $user): void
    {
        // Atomic insert-or-ignore: the unique (pool_id, user_id) index dedupes concurrent first-time
        // views (e.g. React StrictMode's double-invoked effect) with no read-then-write race and no
        // thrown unique violation — the latter would poison an enclosing Postgres transaction.
        $this->briefedUsers()->newPivotStatement()->insertOrIgnore([
            'pool_id' => $this->id,
            'user_id' => $user->id,
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    }
}
