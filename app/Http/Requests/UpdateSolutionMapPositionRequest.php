<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

/**
 * Persists the (x,y) position of a hub dragged in the global ecosystem map
 * (`ecosystem-map.js::startHubDrag`) — a silent auto-save on every drag, with
 * no panel/form. Same permission as editing the Solution itself.
 */
class UpdateSolutionMapPositionRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()?->can('update', $this->route('solution')) ?? false;
    }

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            'x' => ['required', 'numeric'],
            'y' => ['required', 'numeric'],
        ];
    }
}
