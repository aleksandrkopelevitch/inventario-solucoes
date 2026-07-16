<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

class GenerateDocumentationDraftRequest extends FormRequest
{
    /** Só quem edita a Solução pode gerar rascunho com IA. */
    public function authorize(): bool
    {
        $solution = $this->route('solution');

        return $solution !== null && $this->user()->can('update', $solution);
    }

    /** @return array<string, array<int, string>> */
    public function rules(): array
    {
        return [
            'prompt'      => ['required', 'string', 'max:4000'],
            'media_ids'   => ['array'],
            'media_ids.*' => ['integer'],
            // Snapshot do editor no momento do pedido (Markdown serializado).
            'existing_content' => ['nullable', 'string', 'max:500000'],
        ];
    }
}
