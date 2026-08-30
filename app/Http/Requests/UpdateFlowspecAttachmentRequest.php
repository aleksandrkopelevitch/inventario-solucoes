<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

/**
 * Renaming one piece of a conversation's context.
 *
 * The label is not decoration: FlowspecPromptBuilder heads that attachment's
 * prompt section with it, so this is the one field that lets someone write
 * "estenda o flowSpec Pedidos B2B" and have it land on the right pipeline.
 * Nothing else about an attachment is editable — the content is what was
 * attached, and the token estimate is derived from it.
 */
class UpdateFlowspecAttachmentRequest extends FormRequest
{
    public function authorize(): bool
    {
        $chat = $this->route('chat');

        return $chat !== null && $this->user()->can('update', $chat);
    }

    /** @return array<string, array<int, mixed>> */
    public function rules(): array
    {
        return [
            // Long enough for a descriptive name, short enough that a pill
            // stays a pill — and that a prompt heading stays a heading.
            'label' => ['required', 'string', 'max:120'],
        ];
    }

    /** @return array<string, string> */
    public function messages(): array
    {
        return [
            'label.required' => 'O anexo precisa de um nome.',
            'label.max'      => 'O nome do anexo deve ter no máximo 120 caracteres.',
        ];
    }
}
