<?php

namespace App\View\Components\Diagrams;

use App\Models\Diagram;
use App\Models\Solution;
use App\Services\DiagramCatalogService;
use App\Support\ChainLabeler;
use App\View\Components\Concerns\Renderable;
use Illuminate\Contracts\View\View;
use Illuminate\View\Component;

/**
 * The diagrams index's list, as an updatable slot (`diagrams-index-slot`) —
 * every diagram with its status, its chain summary and the systems it touches.
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
                // The systems this drawing names among its blocks — the one
                // relation a diagram has. Eager-loaded by the service, so a row
                // costs no query. It used to list the pages that explained the
                // diagram, back when a page could point at one.
                'solutions' => $diagram->participants->map(fn (Solution $solution) => [
                    'name' => $solution->name,
                    'url'  => route('solutions.show', $solution),
                ]),
            ]),
        ]);
    }
}
