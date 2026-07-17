<?php

namespace App\Http\Requests;

use App\Enums\IntegrationStatus;
use App\Models\Integration;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rules\Enum;

/**
 * Renames / changes the status of an existing Integration — the one thing
 * data-viz F3 doesn't yet handle on its own (the chain itself is entirely
 * edited from the canvas: node title, protocol, new block, retargeting).
 */
class UpdateIntegrationMetaRequest extends FormRequest
{
    public function authorize(): bool
    {
        $integration = $this->route('integration');

        return $integration instanceof Integration
            && ($this->user()?->can('update', $integration) ?? false);
    }

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            'name'   => ['required', 'string', 'max:255'],
            'status' => ['required', new Enum(IntegrationStatus::class)],
        ];
    }
}
