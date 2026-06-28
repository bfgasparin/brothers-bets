<?php

namespace App\Http\Requests\Manage;

use App\Models\Pool;
use App\Models\Tournament;
use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/**
 * Validates the source/destination pool choice for the admin "copy predictions" tool, which lists a
 * source pool's members so the organizer can pick whom to import. Both pools must belong to the
 * tournament in the URL and be distinct. Like the backfill requests it deliberately ignores any
 * prediction lock — authorisation is solely the {@see manage-tournament} ability.
 */
class CopyPreviewRequest extends FormRequest
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
}
