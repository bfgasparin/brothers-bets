<?php

namespace App\Http\Requests\Manage;

use App\Models\Pool;
use App\Models\Tournament;
use App\Models\User;
use App\Services\Predictions\Import\CorrectedImport;
use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/**
 * Validates the admin-reviewed values posted back from the review screen, then assembles them into
 * a {@see CorrectedImport} for the importer. Like the preview request it bypasses prediction-lock
 * checks; authorisation is the {@see manage-tournament} ability. Both pool strategies are accepted.
 */
class BackfillCommitRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()?->can('manage-tournament') ?? false;
    }

    /**
     * @return array<string, ValidationRule|array<mixed>|string>
     */
    public function rules(): array
    {
        $groupIds = $this->tournament()->groupFixtures()->pluck('id')->all();
        $knockoutIds = $this->tournament()->knockoutFixtures()->pluck('id')->all();

        return [
            'pool_id' => ['required', 'integer', Rule::exists('pools', 'id')->where('tournament_id', $this->tournament()->id)],
            'user_id' => ['required', 'integer', 'exists:users,id'],
            'overwrite' => ['sometimes', 'boolean'],

            'group' => ['present', 'array'],
            'group.*.fixture_id' => ['required', 'integer', Rule::in($groupIds)],
            'group.*.home_goals' => ['required', 'integer', 'min:0', 'max:99'],
            'group.*.away_goals' => ['required', 'integer', 'min:0', 'max:99'],

            // Store the JSON's authored knockout bracket verbatim (upfront only) instead of deriving it.
            'literal' => ['sometimes', 'boolean'],

            'knockout' => ['present', 'array'],
            'knockout.*.fixture_id' => ['required', 'integer', Rule::in($knockoutIds)],
            'knockout.*.home_goals' => ['nullable', 'integer', 'min:0', 'max:99'],
            'knockout.*.away_goals' => ['nullable', 'integer', 'min:0', 'max:99'],
            'knockout.*.advancing_team_id' => ['nullable', 'integer'],
            // The JSON's authored participants, sent only in authored mode (see `literal`).
            'knockout.*.predicted_home_team_id' => ['nullable', 'integer', Rule::exists('teams', 'id')],
            'knockout.*.predicted_away_team_id' => ['nullable', 'integer', Rule::exists('teams', 'id')],

            'thirds_team_ids' => ['present', 'array'],
            'thirds_team_ids.*' => ['integer'],

            // Optional within-group tiebreaks, keyed by group label => stated finishing order.
            'group_standings_team_ids' => ['sometimes', 'array'],
            'group_standings_team_ids.*' => ['array'],
            'group_standings_team_ids.*.*' => ['integer'],
        ];
    }

    public function correctedImport(): CorrectedImport
    {
        $group = array_map(fn (array $row): array => [
            'fixture_id' => (int) $row['fixture_id'],
            'home_goals' => (int) $row['home_goals'],
            'away_goals' => (int) $row['away_goals'],
        ], $this->validated('group'));

        $knockout = array_map(fn (array $row): array => [
            'fixture_id' => (int) $row['fixture_id'],
            'home_goals' => $this->intOrNull($row['home_goals'] ?? null),
            'away_goals' => $this->intOrNull($row['away_goals'] ?? null),
            'advancing_pick' => $this->intOrNull($row['advancing_team_id'] ?? null),
            'predicted_home_team_id' => $this->intOrNull($row['predicted_home_team_id'] ?? null),
            'predicted_away_team_id' => $this->intOrNull($row['predicted_away_team_id'] ?? null),
        ], $this->validated('knockout'));

        $thirds = array_map('intval', $this->validated('thirds_team_ids', []));

        $groupStandings = array_map(
            fn (array $ids): array => array_map('intval', $ids),
            $this->validated('group_standings_team_ids', []),
        );

        return new CorrectedImport($group, $knockout, $thirds, $groupStandings, $this->boolean('literal'));
    }

    public function tournament(): Tournament
    {
        $tournament = $this->route('tournament');

        return $tournament instanceof Tournament ? $tournament : Tournament::findOrFail($tournament);
    }

    public function pool(): ?Pool
    {
        return Pool::find($this->input('pool_id'));
    }

    public function targetUser(): User
    {
        return User::findOrFail($this->integer('user_id'));
    }

    private function intOrNull(mixed $value): ?int
    {
        return $value === null || $value === '' ? null : (int) $value;
    }
}
