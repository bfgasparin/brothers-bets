<?php

namespace App\Http\Requests\Manage;

use App\Models\Pool;
use App\Models\Tournament;
use App\Models\User;
use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/**
 * Validates an admin's pasted backfill JSON before it is previewed. Deliberately does NOT check the
 * pool's prediction lock or per-phase windows — backfilling after the lock is the entire point;
 * authorisation is solely the {@see manage-tournament} ability. Both pool strategies are accepted.
 */
class BackfillPreviewRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()?->can('manage-tournament') ?? false;
    }

    /**
     * Decode the pasted JSON text into the structured `payload` the parser consumes.
     */
    protected function prepareForValidation(): void
    {
        $raw = $this->input('json');

        if (is_string($raw)) {
            $decoded = json_decode($raw, true);
            $this->merge(['payload' => is_array($decoded) ? $decoded : null]);
        }
    }

    /**
     * @return array<string, ValidationRule|array<mixed>|string>
     */
    public function rules(): array
    {
        return [
            'pool_id' => ['required', 'integer', Rule::exists('pools', 'id')->where('tournament_id', $this->tournament()->id)],
            'user_id' => ['required', 'integer', 'exists:users,id'],
            'json' => ['required', 'string'],
            'payload' => ['required', 'array'],
            'payload.matches' => ['required', 'array', 'min:1'],
            // Preview the JSON's bracket stored verbatim (authored mode) instead of the derived one.
            'literal' => ['sometimes', 'boolean'],
        ];
    }

    /**
     * Whether to preview the knockout bracket exactly as authored in the JSON (upfront pools only),
     * bypassing the FIFA derivation.
     */
    public function literal(): bool
    {
        return $this->boolean('literal');
    }

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        return [
            'payload.required' => __('The pasted content is not valid JSON.'),
            'payload.array' => __('The pasted content is not valid JSON.'),
            'payload.matches.required' => __('The JSON must contain a "matches" array.'),
        ];
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

    /**
     * The decoded JSON blob, taken raw so the parser (which is defensive) sees every nested key.
     *
     * @return array<string, mixed>
     */
    public function payload(): array
    {
        return (array) $this->input('payload');
    }
}
