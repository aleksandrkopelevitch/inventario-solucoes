<?php

namespace App\Http\Requests;

use App\Models\DocumentationPage;
use App\Models\Notebook;
use Illuminate\Foundation\Http\FormRequest;

/**
 * Creating a page: its title, and optionally the page it goes UNDER
 * ("Nova subpágina" in the rail's row menu). Renaming has its own request —
 * `SaveDocumentationPageTitleRequest` — because a rename can never change where
 * a page sits in the tree.
 *
 * The `parent` rule is what holds the depth cap on the way in: the chosen
 * parent has to be a page of THIS notebook (never another caderno's, which is
 * why it's looked up through the notebook's own relation rather than by
 * `exists:documentation_pages,id`) and has to have room for a child under it
 * (`DocumentationPage::canReceiveChildren()` — anything at the last level
 * doesn't).
 */
class StoreDocumentationPageRequest extends FormRequest
{
    /** Same rule as SaveDocumentationRequest: only whoever may edit the caderno. */
    public function authorize(): bool
    {
        $notebook = $this->notebook();

        return $notebook !== null && $this->user()->can('update', $notebook);
    }

    /** @return array<string, array<int, mixed>> */
    public function rules(): array
    {
        return [
            'title'  => ['required', 'string', 'max:255'],
            'parent' => [
                'nullable',
                'integer',
                function (string $attribute, mixed $value, callable $fail): void {
                    $parent = $this->parentPage();

                    if (! $parent) {
                        $fail('A página escolhida não existe mais.');

                        return;
                    }

                    if (! $parent->canReceiveChildren()) {
                        $fail('Esta página já está no último nível — ela não pode receber subpáginas.');
                    }
                },
            ],
        ];
    }

    /**
     * The page the new one goes under, or null for a root page. Resolved
     * through the notebook's relation — a `parent` id belonging to another
     * caderno's tree simply doesn't exist from here.
     */
    public function parentPage(): ?DocumentationPage
    {
        $id = $this->input('parent');

        if (! is_numeric($id)) {
            return null;
        }

        return $this->notebook()?->pages()->whereKey((int) $id)->first();
    }

    private function notebook(): ?Notebook
    {
        return $this->route('notebook');
    }
}
