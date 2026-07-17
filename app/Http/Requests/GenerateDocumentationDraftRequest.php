<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

class GenerateDocumentationDraftRequest extends FormRequest
{
    /** Only someone who can edit the Solution can generate an AI draft. */
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
            // Snapshot of the editor at the moment of the request (serialized Markdown).
            'existing_content' => ['nullable', 'string', 'max:500000'],
        ];
    }
}
