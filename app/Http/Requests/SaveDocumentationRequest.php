<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

class SaveDocumentationRequest extends FormRequest
{
    /** Só quem pode editar o recurso (admin, via Policy::update) salva a doc. */
    public function authorize(): bool
    {
        $model = $this->route('integration') ?? $this->route('solution') ?? $this->route('group');

        return $model !== null && $this->user()->can('update', $model);
    }

    /** @return array<string, array<int, string>> */
    public function rules(): array
    {
        return [
            // Markdown + notação estendida GitBook. Nullable: doc pode ser esvaziada.
            'documentation' => ['nullable', 'string', 'max:500000'],
        ];
    }
}
