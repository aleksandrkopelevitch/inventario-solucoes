<?php

namespace App\View\Components\Documentation;

use App\Models\DocumentationPage;
use App\Models\Notebook;
use App\View\Components\Concerns\Renderable;
use Illuminate\Contracts\View\View;
use Illuminate\View\Component;

/**
 * The page's title in the editor's top bar, edited in place.
 *
 * Its own updatable slot because a rename has to show up in two places at once
 * — here and in the rail — and they are not in the same subtree. The rename
 * endpoint answers with both (§ Multiple different slots).
 *
 * A rename never changes the slug, so the URL survives it: that is what lets
 * this be a slot swap instead of the `redirect` the endpoint used to answer
 * with, back when the only way to rename was the rail's own hidden form.
 */
class PageTitle extends Component
{
    use Renderable;

    public const DOM_ID = 'documentation-page-title-slot';

    public function __construct(
        public Notebook $notebook,
        public DocumentationPage $page,
    ) {}

    public static function slot(Notebook $notebook, DocumentationPage $page): array
    {
        return (new static($notebook, $page))->toSlot(self::DOM_ID);
    }

    public function render(): View
    {
        return view('components.documentation.page-title', [
            'domId'  => self::DOM_ID,
            'title'  => $this->page->title,
            'action' => route('notebooks.pages.rename', [$this->notebook, $this->page]),
            // The policy delegates to the caderno; the relation is already in
            // hand, so setting it avoids the lazy load it would otherwise do.
            'canEdit' => auth()->user()?->can('update', $this->page->setRelation('notebook', $this->notebook)) ?? false,
        ]);
    }
}
