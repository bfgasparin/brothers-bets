<?php

namespace App\Http\Requests\Predictions;

use App\Models\Entry;
use App\Models\Pool;
use Illuminate\Foundation\Http\FormRequest;

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
        if ($this->user() === null
            || ! $this->pool()->acceptsPredictions()
            || ! $this->pool()->isJoinedBy($this->user())) {
            return false;
        }

        // An organizer-authored bracket must never be re-derived by a player save (every save path
        // re-runs the cascade). The wizard already blocks these entries; reject direct posts too.
        return ! ($this->existingEntry()?->hasAuthoredBracket() ?? false);
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
