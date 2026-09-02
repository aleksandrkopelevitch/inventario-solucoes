<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

class StoreDocumentationChatMessageRequest extends FormRequest
{
    /** Only someone who can edit the Solution can talk to the Documentation Assistant. */
    public function authorize(): bool
    {
        $notebook = $this->route('notebook');

        return $notebook !== null && $this->user()->can('update', $notebook);
    }

    /** @return array<string, array<int, string>> */
    public function rules(): array
    {
        return [
            'message'     => ['required', 'string', 'max:4000'],
            'media_ids'   => ['array'],
            'media_ids.*' => ['integer'],
            // Other documentation pages to hand the assistant as reference.
            // DECLARED here on purpose, however obvious it looks: `validated()`
            // returns only what the rules name, so an undeclared key is dropped
            // in silence and every picked page is ignored with no error
            // anywhere (the same way `filter.scopes` broke the documentation
            // search the first time round).
            //
            // `exists`, and deliberately not scoped to a caderno: a page from
            // another caderno is the normal case here — it is usually the system
            // on the other end of what is being documented. Reading it is not a
            // widening either, since NotebookPolicy::view is open to every
            // signed-in account and this endpoint already demands `update` on
            // the caderno being written.
            'page_ids'   => ['array'],
            'page_ids.*' => ['integer', 'exists:documentation_pages,id'],
            // Editor's live Markdown snapshot at send time (may include unsaved edits).
            'existing_content' => ['nullable', 'string', 'max:500000'],
        ];
    }
}
