<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

/** Title of a DocumentationPage — used both to create (store) and to rename. */
class SaveDocumentationPageTitleRequest extends FormRequest
{
    /** Same rule as SaveDocumentationRequest: only whoever edits the container (Solution/Group). */
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
