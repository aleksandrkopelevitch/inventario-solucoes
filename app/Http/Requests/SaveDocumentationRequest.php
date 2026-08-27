<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

class SaveDocumentationRequest extends FormRequest
{
    /** Only whoever can edit the resource (admin, via Policy::update) saves the doc. */
    public function authorize(): bool
    {
        $model = $this->route('diagram') ?? $this->route('notebook');

        return $model !== null && $this->user()->can('update', $model);
    }

    /** @return array<string, array<int, string>> */
    public function rules(): array
    {
        return [
            // Markdown + extended GitBook notation. Nullable: the doc can be emptied out.
            'documentation' => ['nullable', 'string', 'max:500000'],
        ];
    }
}
