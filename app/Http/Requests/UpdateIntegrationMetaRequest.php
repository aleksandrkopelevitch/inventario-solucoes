<?php

namespace App\Http\Requests;

use App\Enums\IntegrationStatus;
use App\Models\Integration;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rules\Enum;

/**
 * Renomeia / muda o status de uma Integration já existente — o que o
 * data-viz F3 ainda não sabe fazer sozinho (a chain em si é toda editada
 * pelo próprio canvas: título de nó, protocolo, novo bloco, religação).
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
