<?php

namespace App\View\Components\Diagrams;

use App\Models\Diagram;
use App\Models\DocumentationGroup;
use App\Models\DocumentationPage;
use App\Models\Solution;
use App\Services\DiagramCatalogService;
use App\Support\ChainLabeler;
use App\View\Components\Concerns\Renderable;
use Illuminate\Contracts\View\View;
use Illuminate\View\Component;

/**
 * The diagrams index's list, as an updatable slot (`diagrams-index-slot`) —
 * every diagram with its status, its chain summary and where it is explained.
 *
 * Filtering goes through `Diagram::scopeFilter()` (via
 * `DiagramCatalogService::list()`) rather than being spelled out here, so the
 * list and anything else that has to agree with it read one definition — the
 * same arrangement `Solutions\Index` and `Solutions\ResultsCount` have.
 */
class Index extends Component
{
    use Renderable;

    public const DOM_ID = 'diagrams-index-slot';

    /** @param  array<string, mixed>  $filters */
    public function __construct(public array $filters = []) {}

    /** @param  array<string, mixed>  $filters */
    public static function slot(array $filters = []): array
    {
        return (new static($filters))->toSlot(self::DOM_ID);
    }

    public function render(): View
    {
        $diagrams = app(DiagramCatalogService::class)->list($this->filters);
        $labeler = new ChainLabeler;
        $solutions = $labeler->resolveSolutions($diagrams->pluck('chain'));

        return view('components.diagrams.index', [
            'domId'   => self::DOM_ID,
            'filters' => $this->filters,
            'rows'    => $diagrams->map(fn (Diagram $diagram) => [
                'diagram' => $diagram,
                'summary' => $diagram->chain ? $labeler->label($diagram->chain, $solutions) : null,
                'blocks'  => count($diagram->chain['nodes'] ?? []),
                'url'     => route('diagrams.show', $diagram),
                // Where this drawing is explained. Eager-loaded with its
                // container by the service, so naming the solution/group a
                // page belongs to costs no query per row.
                'pages' => $diagram->pages->map(fn (DocumentationPage $page) => [
                    'title'     => $page->title,
                    'container' => $page->container?->name,
                    'url'       => $this->pageUrl($page),
                ]),
            ]),
        ]);
    }

    /**
     * A page's editor URL, which depends on what contains it — the two
     * containers have separate route groups (a Solution's pages live under
     * that solution, a standalone group's under the group).
     */
    private function pageUrl(DocumentationPage $page): ?string
    {
        return match (true) {
            $page->container instanceof Solution           => route('solutions.docs.page.edit', [$page->container, $page]),
            $page->container instanceof DocumentationGroup => route('documentation.groups.pages.edit', [$page->container, $page]),
            default                                        => null,
        };
    }
}
