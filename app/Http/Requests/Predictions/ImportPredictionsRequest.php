<?php

namespace App\Http\Requests\Predictions;

use App\Enums\PredictionWindowStatus;
use App\Models\Pool;
use App\Services\Predictions\PredictionImporter;
use App\Services\Predictions\PredictionWindowResolver;
use Illuminate\Contracts\Validation\Validator;

/**
 * Authorises and validates importing the user's predictions from a sibling pool. Unlike the save
 * requests it does not ride {@see Pool::acceptsPredictions()} — that is false for a phased pool once
 * the group lock passes, yet an open knockout round is still importable — so it gates on "any
 * window open" instead, mirroring {@see UpdateKnockoutPredictionsRequest::authorize()}.
 */
class ImportPredictionsRequest extends PredictionRequest
{
    private ?Pool $sourcePool = null;

    public function authorize(): bool
    {
        $pool = $this->pool();

        if ($this->user() === null || ! $pool->isJoinedBy($this->user())) {
            return false;
        }

        // An organizer-authored bracket must never be re-derived; importing would cascade over it.
        if ($this->existingEntry()?->hasAuthoredBracket() ?? false) {
            return false;
        }

        return in_array(
            PredictionWindowStatus::Open,
            app(PredictionWindowResolver::class)->windows($pool),
            true,
        );
    }

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            'source_pool' => ['required', 'string'],
        ];
    }

    /**
     * The source must be one the importer would actually offer — same tournament, the user has an
     * entry, and there is real data to copy into an open window. Checking against that one list
     * keeps eligibility defined in a single place.
     */
    public function withValidator(Validator $validator): void
    {
        $validator->after(function (Validator $validator): void {
            $eligible = collect(app(PredictionImporter::class)->eligibleSources($this->pool(), $this->user()))
                ->pluck('slug');

            if (! $eligible->contains($this->input('source_pool'))) {
                $validator->errors()->add('source_pool', __('That pool can’t be imported from right now.'));
            }
        });
    }

    /**
     * The validated source pool. Only safe to call once validation has passed.
     */
    public function sourcePool(): Pool
    {
        return $this->sourcePool ??= Pool::where('slug', $this->input('source_pool'))->firstOrFail();
    }
}
