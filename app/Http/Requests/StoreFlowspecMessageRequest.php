<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

/**
 * A following turn in an existing conversation — message only.
 *
 * Deliberately carries no context fields. The conversation's context lives on
 * the chat (FlowspecAttachment) and is managed by its own endpoint, so a second
 * question no longer silently loses the documentation the first one was
 * answered with — which is exactly what the old per-message
 * `solutions`/`documents`/`reference_flowspec` fields did.
 */
class StoreFlowspecMessageRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()?->can('update', $this->route('chat')) ?? false;
    }

    /** @return array<string, mixed> */
    public function rules(): array
    {
        return [
            'message' => ['required', 'string', 'max:8000'],
        ];
    }
}
