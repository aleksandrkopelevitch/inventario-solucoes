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
            // The page version the editor's text is based on
            // (DocumentationPage::version()); a stale one is a 409
            // (SavePageContent). Nullable because a tab opened before this
            // shipped has none — making it required is a follow-up once those
            // tabs are gone (docs-and-specs/mcp-write-tools.md §14).
            'base_version' => ['nullable', 'string', 'size:32'],
        ];
    }
}
