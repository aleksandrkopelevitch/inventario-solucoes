<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

class AttachFlowspecToIntegrationRequest extends FormRequest
{
    public function authorize(): bool
    {
        // Dono do chat; a permissão de editar a Integration alvo é checada no
        // controller (`authorize('update', $integration)`), já com o model.
        return $this->user()?->can('update', $this->route('chat')) ?? false;
    }

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            'integration_id' => ['required', 'integer', 'exists:integrations,id'],
        ];
    }
}
