<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

class AttachFlowspecToIntegrationRequest extends FormRequest
{
    public function authorize(): bool
    {
        // Owner of the chat; permission to edit the target Integration is checked
        // in the controller (`authorize('update', $integration)`), once the model is in hand.
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
