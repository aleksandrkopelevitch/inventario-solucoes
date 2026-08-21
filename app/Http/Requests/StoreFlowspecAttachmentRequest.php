<?php

namespace App\Http\Requests;

use App\Http\Requests\Concerns\GuardsFlowspecContext;
use App\Models\FlowspecChat;
use Illuminate\Contracts\Validation\Validator;
use Illuminate\Foundation\Http\FormRequest;

/**
 * Adding one piece of context to an existing conversation: inventory
 * documentation the picker checked, a file from the user's disk, or a long
 * paste.
 *
 * Three fields, one required — `required_without_all` rather than three
 * separate endpoints, because the composer's 📎 menu, the picker panel and the
 * assistant's own "adicionar ao contexto" buttons all post here and differ only
 * in which field they fill.
 */
class StoreFlowspecAttachmentRequest extends FormRequest
{
    use GuardsFlowspecContext;

    public function authorize(): bool
    {
        return $this->user()?->can('update', $this->chat()) ?? false;
    }

    /** @return array<string, mixed> */
    public function rules(): array
    {
        return [
            ...$this->contextRules(),
            'documents' => ['required_without_all:file,text', 'nullable', 'array', 'max:' . config('services.flowspec.max_attachments')],
            'file'      => ['required_without_all:documents,text', 'file', 'mimes:' . self::ACCEPTED_MIMES, 'max:20480'],
            'text'      => ['required_without_all:documents,file', 'nullable', 'string', 'max:' . config('services.flowspec.max_reference_chars')],
            'label'     => ['nullable', 'string', 'max:255'],
        ];
    }

    /** @return array<string, string> */
    public function messages(): array
    {
        return [
            ...$this->contextMessages(),
            'documents.required_without_all' => 'Escolha um documento, um arquivo ou cole um texto.',
            'file.required_without_all'      => 'Escolha um documento, um arquivo ou cole um texto.',
            'text.required_without_all'      => 'Escolha um documento, um arquivo ou cole um texto.',
        ];
    }

    public function withValidator(Validator $validator): void
    {
        $this->guardContextCount($validator, $this->chat());
    }

    public function chat(): FlowspecChat
    {
        return $this->route('chat');
    }
}
