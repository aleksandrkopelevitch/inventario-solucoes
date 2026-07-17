<?php

namespace App\Http\Requests;

use App\Models\Integration;
use Illuminate\Foundation\Http\FormRequest;

/**
 * Creates a new Integration from the context solution — just the name
 * (optional). The initial chain is {nodes: [root], edges: []}; blocks, edges,
 * and status/rename are handled by data-viz F3 from then on.
 */
class StoreIntegrationRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()?->can('create', Integration::class) ?? false;
    }

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            'name' => ['nullable', 'string', 'max:255'],
        ];
    }
}
