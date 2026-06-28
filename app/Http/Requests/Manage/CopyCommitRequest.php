<?php

namespace App\Http\Requests\Manage;

use App\Models\Pool;
use App\Models\Tournament;
use App\Models\User;
use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/**
 * Validates a bulk copy from one pool into a sibling pool of the same tournament. Each selected user
 * must be a member of the SOURCE pool — the importer copies that user's own source entry, so a
 * non-member would be a silent no-op. Deliberately bypasses any prediction lock; authorisation is
 * solely the {@see manage-tournament} ability.
 */
class CopyCommitRequest extends FormRequest
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
        $inTournament = Rule::exists('pools', 'id')->where('tournament_id', $this->tournament()->id);

        return [
            'source_pool_id' => ['required', 'integer', $inTournament],
            'destination_pool_id' => ['required', 'integer', 'different:source_pool_id', $inTournament],
            'overwrite' => ['sometimes', 'boolean'],
            'user_ids' => ['required', 'array', 'min:1'],
            'user_ids.*' => ['integer', Rule::exists('entries', 'user_id')->where('pool_id', (int) $this->input('source_pool_id'))],
        ];
    }

    public function tournament(): Tournament
    {
        $tournament = $this->route('tournament');

        return $tournament instanceof Tournament ? $tournament : Tournament::findOrFail($tournament);
    }

    public function sourcePool(): Pool
    {
        return Pool::findOrFail($this->integer('source_pool_id'));
    }

    public function destinationPool(): Pool
    {
        return Pool::findOrFail($this->integer('destination_pool_id'));
    }

    /**
     * The selected players to import, in the submitted order.
     *
     * @return Collection<int, User>
     */
    public function users(): Collection
    {
        return User::whereIn('id', $this->validated('user_ids'))->get();
    }
}
