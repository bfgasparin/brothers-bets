<?php

namespace App\Http\Requests\Pools;

use App\Models\Pool;
use Illuminate\Foundation\Http\FormRequest;

/**
 * Authorises a player joining a pool. Joining is the act of entering, so it follows the
 * same window as making predictions: a player can join while predictions are still open, never
 * after the group-stage lock.
 */
class JoinPoolRequest extends FormRequest
{
    public function pool(): Pool
    {
        return $this->route('pool');
    }

    public function authorize(): bool
    {
        return $this->user() !== null && $this->pool()->acceptsJoins();
    }

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [];
    }
}
