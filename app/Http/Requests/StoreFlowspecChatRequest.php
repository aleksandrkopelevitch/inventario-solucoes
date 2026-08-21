<?php

namespace App\Http\Requests;

use App\Http\Requests\Concerns\GuardsFlowspecContext;
use App\Models\FlowspecChat;
use Illuminate\Contracts\Validation\Validator;
use Illuminate\Foundation\Http\FormRequest;

/**
 * The first message of a new conversation — and the only endpoint that accepts
 * context STAGED in the composer rather than already attached.
 *
 * On the new-chat screen there is no chat to attach to yet, so the composer
 * holds picked documents, chosen files and long pastes client-side and submits
 * them with this request. Every following turn attaches immediately instead
 * (FlowspecAttachmentController), because by then a chat exists to attach to.
 */
class StoreFlowspecChatRequest extends FormRequest
{
    use GuardsFlowspecContext;

    public function authorize(): bool
    {
        return $this->user()?->can('create', FlowspecChat::class) ?? false;
    }

    /** @return array<string, mixed> */
    public function rules(): array
    {
        return [
            'message' => ['required', 'string', 'max:8000'],
            ...$this->contextRules(),
        ];
    }

    /** @return array<string, string> */
    public function messages(): array
    {
        return $this->contextMessages();
    }

    public function withValidator(Validator $validator): void
    {
        // No chat yet, so nothing is already attached to count against.
        $this->guardContextCount($validator, null);
    }
}
