<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

/**
 * Renaming a DocumentationPage — the title and nothing else: a rename can
 * never move a page in the tree, and the slug (its URL) stays put too. Creating
 * one goes through `StoreDocumentationPageRequest`, which also accepts the
 * parent the new page goes under.
 */
class SaveDocumentationPageTitleRequest extends FormRequest
{
    /** Same rule as SaveDocumentationRequest: only whoever edits the container (Solution/Group). */
    public function authorize(): bool
    {
        $model = $this->route('notebook');

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
