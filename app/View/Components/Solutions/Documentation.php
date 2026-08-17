<?php

namespace App\View\Components\Solutions;

use App\Models\DocumentationPage;
use App\Models\Solution;
use App\View\Components\Concerns\Renderable;
use Illuminate\Contracts\View\View;
use Illuminate\View\Component;

/**
 * Documentation column of the solution detail page's "integrações +
 * documentação" card (the integrations are the other half — see
 * `Solutions\IntegrationsMap`). The Solution has a tree of 1..N pages (no
 * longer a single blob), so this lists the titles linking to each page; the
 * full content lives in the editor's own screen (`solutions.docs.edit`, which
 * resolves/opens the first page). A page can also be CREATED from here, and
 * the endpoint answers with a redirect straight into the new page's editor —
 * the same one gesture the pages rail inside the editor gives.
 *
 * Updatable slot: `Documentation::slot($solution)` — the editor's save
 * returns it to keep this section fresh if the user goes back to the detail page.
 */
class Documentation extends Component
{
    use Renderable;

    public const DOM_ID = 'solution-documentation-slot';

    public function __construct(public Solution $solution) {}

    public static function slot(Solution $solution): array
    {
        return (new static($solution))->toSlot(self::DOM_ID);
    }

    public function render(): View
    {
        return view('components.solutions.documentation', [
            'domId' => self::DOM_ID,
            'pages' => $this->solution->pages()->get()->map(fn (DocumentationPage $page) => [
                'title'      => $page->title,
                'url'        => route('solutions.docs.page.edit', [$this->solution, $page]),
                'hasContent' => trim((string) $page->documentation) !== '',
            ]),
            'editUrl'       => route('solutions.docs.edit', $this->solution),
            'createPageUrl' => route('solutions.docs.pages.store', $this->solution),
        ]);
    }
}
