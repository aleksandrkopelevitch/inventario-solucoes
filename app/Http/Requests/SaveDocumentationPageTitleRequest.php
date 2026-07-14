<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

/** Título de uma DocumentationPage — usado tanto pra criar (store) quanto renomear. */
class SaveDocumentationPageTitleRequest extends FormRequest
{
    /** Mesma regra de SaveDocumentationRequest: só quem edita o container (Solution/Group). */
    public function authorize(): bool
    {
        $model = $this->route('solution') ?? $this->route('group');

        return $model !== null && $this->user()->can('update', $model);
    }

    /** @return array<string, array<int, string>> */
    public function rules(): array
    {
        return [
            'title' => ['required', 'string', 'max:255'],
        ];
    }
}
