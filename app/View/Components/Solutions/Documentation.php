<?php

namespace App\View\Components\Solutions;

use App\Models\Solution;
use App\Services\DocumentationPageService;
use App\View\Components\Concerns\Renderable;
use Illuminate\Contracts\View\View;
use Illuminate\View\Component;

/**
 * Documentation column of the solution detail page's "integrações +
 * documentação" card (the integrations are the other half — see
 * `Solutions\IntegrationsMap`). The Solution has a tree of 1..N pages (no
 * longer a single blob), so this lists the titles linking to each page; the
 * full content lives in the editor's own screen (`solutions.docs.edit`, which
 * resolves/opens the first page). Subpages are listed indented under their
 * page — the tree is two levels deep. A page can also be CREATED from here, and
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
            // Reading order + depth, not the flat relation: a subpage's
            // `position` only orders it among its siblings, so listing this
            // card by `position` alone would scatter subpages among the pages
            // they belong to.
            'pages' => app(DocumentationPageService::class)->tree($this->solution)->map(fn (array $row) => [
                'title'      => $row['page']->title,
                'depth'      => $row['depth'],
                'url'        => route('solutions.docs.page.edit', [$this->solution, $row['page']]),
                'hasContent' => trim((string) $row['page']->documentation) !== '',
            ]),
            'editUrl'       => route('solutions.docs.edit', $this->solution),
            'createPageUrl' => route('solutions.docs.pages.store', $this->solution),
        ]);
    }
}
