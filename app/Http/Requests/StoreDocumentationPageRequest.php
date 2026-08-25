<?php

namespace App\Http\Requests;

use App\Models\DocumentationPage;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Foundation\Http\FormRequest;

/**
 * Creating a page: its title, and optionally the page it goes UNDER
 * ("Nova subpágina" in the rail's row menu). Renaming has its own request —
 * `SaveDocumentationPageTitleRequest` — because a rename can never change where
 * a page sits in the tree.
 *
 * The `parent` rule is what keeps the tree two levels deep on the way in: the
 * chosen parent has to be a page of THIS container (never another solution's,
 * which is why it's looked up through the container's own relation rather than
 * by `exists:documentation_pages,id`) and has to be a root itself.
 */
class StoreDocumentationPageRequest extends FormRequest
{
    /** Same rule as SaveDocumentationRequest: only whoever edits the container (Solution/Group). */
    public function authorize(): bool
    {
        $model = $this->container();

        return $model !== null && $this->user()->can('update', $model);
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
                        $fail('Uma subpágina não pode receber outra subpágina.');
                    }
                },
            ],
        ];
    }

    /**
     * The page the new one goes under, or null for a root page. Resolved
     * through the container's relation — a `parent` id belonging to another
     * solution's tree simply doesn't exist from here.
     */
    public function parentPage(): ?DocumentationPage
    {
        $id = $this->input('parent');

        if (! is_numeric($id)) {
            return null;
        }

        return $this->container()?->pages()->whereKey((int) $id)->first();
    }

    private function container(): ?Model
    {
        return $this->route('solution') ?? $this->route('group');
    }
}
