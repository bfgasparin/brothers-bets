<?php

namespace App\Models;

use App\Enums\LeaderboardCategory;
use Database\Factories\EntryFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

#[Fillable(['pool_id', 'user_id', 'total_points', 'rank', 'previous_rank'])]
class Entry extends Model
{
    /** @use HasFactory<EntryFactory> */
    use HasFactory;

    /**
     * Get the attributes that should be cast.
     *
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'total_points' => 'integer',
            'rank' => 'integer',
            'previous_rank' => 'integer',
            'authored_bracket_at' => 'datetime',
        ];
    }

    /**
     * Whether this entry's knockout bracket was backfilled "as authored" from a JSON blob and must
     * therefore never be re-derived from the group scores (the prediction page is blocked for it).
     */
    public function hasAuthoredBracket(): bool
    {
        return $this->authored_bracket_at !== null;
    }

    /**
     * @return BelongsTo<Pool, $this>
     */
    public function pool(): BelongsTo
    {
        return $this->belongsTo(Pool::class);
    }

    /**
     * @return BelongsTo<User, $this>
     */
    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
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
     * This entry's manual orderings of ties the ranking engine could not resolve.
     *
     * @return HasMany<EntryGroupOrdering, $this>
     */
    public function groupOrderings(): HasMany
    {
        return $this->hasMany(EntryGroupOrdering::class);
    }

    /**
     * This entry's per-leaderboard standings, one row per {@see LeaderboardCategory}.
     *
     * @return HasMany<LeaderboardStanding, $this>
     */
    public function standings(): HasMany
    {
        return $this->hasMany(LeaderboardStanding::class);
    }
}
