<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

class StoreDocumentationChatMessageRequest extends FormRequest
{
    /** Only someone who can edit the Solution can talk to the Documentation Assistant. */
    public function authorize(): bool
    {
        $solution = $this->route('solution');

        return $solution !== null && $this->user()->can('update', $solution);
    }

    /** @return array<string, array<int, string>> */
    public function rules(): array
    {
        return [
            'message'     => ['required', 'string', 'max:4000'],
            'media_ids'   => ['array'],
            'media_ids.*' => ['integer'],
            // Editor's live Markdown snapshot at send time (may include unsaved edits).
            'existing_content' => ['nullable', 'string', 'max:500000'],
        ];
    }
}
