<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

class StoreContextDocumentRequest extends FormRequest
{
    /** Só quem edita a Solução gerencia os documentos de contexto dela. */
    public function authorize(): bool
    {
        $solution = $this->route('solution');

        return $solution !== null && $this->user()->can('update', $solution);
    }

    /** @return array<string, array<int, string>> */
    public function rules(): array
    {
        return [
            // Formatos que o Claude ingere nativamente (PDF/imagem como anexo,
            // texto embutido no prompt — ver DocumentationDraftService).
            'file' => [
                'required',
                'file',
                'max:20480', // 20 MB
                'mimes:pdf,png,jpg,jpeg,gif,webp,txt,md,csv,json,yaml,yml',
            ],
        ];
    }
}
