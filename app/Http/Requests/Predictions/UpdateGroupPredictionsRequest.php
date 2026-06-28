<?php

namespace App\Http\Requests\Predictions;

use Illuminate\Validation\Rule;

class UpdateGroupPredictionsRequest extends PredictionRequest
{
    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        $groupFixtureIds = $this->pool()->tournament->groupFixtures()->pluck('id')->all();

        return [
            'predictions' => ['required', 'array'],
            'predictions.*.fixture_id' => ['required', 'integer', Rule::in($groupFixtureIds)],
            'predictions.*.home_goals' => ['required', 'integer', 'min:0', 'max:99'],
            'predictions.*.away_goals' => ['required', 'integer', 'min:0', 'max:99'],
        ];
    }

    /**
     * The validated group predictions to persist — for a per-match pool, only those whose match is
     * still open (others gate the whole stage at once, so all are kept).
     *
     * @return list<array{fixture_id: int, home_goals: int, away_goals: int}>
     */
    public function predictionsForPersistence(): array
    {
        $fixturesById = $this->pool()->tournament->groupFixtures()->with('phase')->get()->keyBy('id');

        return $this->openPredictions($this->validated('predictions'), $fixturesById);
    }
}
