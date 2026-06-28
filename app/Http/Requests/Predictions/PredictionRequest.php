<?php

namespace App\Http\Requests\Predictions;

use App\Enums\PredictionWindowStatus;
use App\Models\Entry;
use App\Models\Fixture;
use App\Models\Pool;
use App\Services\Predictions\PredictionWindowResolver;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Support\Collection;

/**
 * Shared base for the prediction save requests: authorises against the pool's prediction lock
 * and resolves the authenticated user's (draft) entry for this pool.
 */
abstract class PredictionRequest extends FormRequest
{
    private ?Entry $entry = null;

    public function pool(): Pool
    {
        return $this->route('pool');
    }

    public function authorize(): bool
    {
        $user = $this->user();
        $pool = $this->pool();

        if ($user === null || ! $pool->isJoinedBy($user)) {
            return false;
        }

        // An organizer-authored bracket must never be re-derived by a player save (every save path
        // re-runs the cascade). The wizard already blocks these entries; reject direct posts too.
        if ($this->existingEntry()?->hasAuthoredBracket() ?? false) {
            return false;
        }

        // A per-match pool gates each fixture individually at save time — locked fixtures are
        // filtered out via {@see openPredictions()} rather than rejecting the whole save (a single
        // match can lock mid-batch). Every other strategy locks the whole pool/round at once.
        if ($pool->usesPerMatchPredictionWindows()) {
            return true;
        }

        return $pool->acceptsPredictions();
    }

    /**
     * For a per-match pool, keep only the submitted predictions whose fixture is currently open
     * (judged by the server clock); other strategies gate the whole pool/round in {@see authorize()},
     * so every submitted prediction is kept. Defends against a stale client saving a match that has
     * since locked.
     *
     * @param  list<array<string, mixed>>  $predictions
     * @param  Collection<int, Fixture>  $fixturesById
     * @return list<array<string, mixed>>
     */
    protected function openPredictions(array $predictions, Collection $fixturesById): array
    {
        if (! $this->pool()->usesPerMatchPredictionWindows()) {
            return array_values($predictions);
        }

        $resolver = app(PredictionWindowResolver::class);

        return array_values(array_filter($predictions, function (array $prediction) use ($resolver, $fixturesById): bool {
            $fixture = $fixturesById->get($prediction['fixture_id'] ?? null);

            return $fixture !== null
                && $resolver->statusForFixture($this->pool(), $fixture) === PredictionWindowStatus::Open;
        }));
    }

    /**
     * The current user's entry for this pool. The user must have joined first; authorize() runs
     * before this, so on the save path the entry always exists.
     */
    public function entry(): Entry
    {
        $entry = $this->existingEntry();

        if ($entry === null) {
            abort(404);
        }

        return $entry;
    }

    /**
     * The current user's entry for this pool, or null when they have not joined — resolved once and
     * shared between {@see authorize()} and {@see Entry()}.
     */
    protected function existingEntry(): ?Entry
    {
        if ($this->user() === null) {
            return null;
        }

        return $this->entry ??= Entry::query()
            ->where('pool_id', $this->pool()->id)
            ->where('user_id', $this->user()->id)
            ->first();
    }
}
