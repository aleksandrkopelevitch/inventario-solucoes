<?php

namespace App\View\Components\Solutions;

use App\Models\Diagram;
use App\Models\Solution;
use App\Support\ChainLabeler;
use App\View\Components\Concerns\Renderable;
use Illuminate\Contracts\View\View;
use Illuminate\Support\Collection;
use Illuminate\View\Component;

/**
 * Plain nav list of the diagrams a solution appears in: name, chain summary
 * and status, with a creation form (optional name) and a delete action. It's
 * the left column of the solution detail page's "diagramas + documentação"
 * card — `Solutions\Documentation` is the right one, and the card's frame
 * lives in `solutions/show.blade.php` since each column is its own updatable
 * slot (creating on one side must not re-render the other).
 *
 * "Appears in" is the union of the two ways a diagram reaches a solution, and
 * both belong on this card because a reader asking "what drawings explain this
 * system?" does not care which one applies:
 *
 * - it's a PARTICIPANT — the chain has a `system` node referencing this
 *   solution, derived into the `diagram_solution` pivot by
 *   `SyncDiagramFromChain`. This is the topology answer.
 * - it's REFERENCED BY THIS SOLUTION'S DOCUMENTATION — one of the solution's
 *   pages points at the diagram (`documentation_pages.diagram_id`). A drawing
 *   can legitimately explain a solution without the solution being a box in
 *   it (an overview of the surrounding flow, for instance).
 *
 * Each row links straight to the diagram's own canvas page — the graphical
 * chain editor doesn't live inline here, and neither does the diagram's
 * name/status (`Diagrams\Meta`, in that page's top bar).
 */
class Diagrams extends Component
{
    use Renderable;

    public const DOM_ID = 'solution-diagram-titles-slot';

    public function __construct(public Solution $solution) {}

    public static function slot(Solution $solution): array
    {
        return (new static($solution))->toSlot(self::DOM_ID);
    }

    public function render(): View
    {
        $diagrams = $this->diagrams();
        $labeler = new ChainLabeler;
        $solutions = $labeler->resolveSolutions($diagrams->pluck('chain'));

        return view('components.solutions.diagrams', [
            'domId'    => self::DOM_ID,
            'solution' => $this->solution,
            'rows'     => $diagrams->map(fn (Diagram $diagram) => [
                'diagram' => $diagram,
                'summary' => $diagram->chain ? $labeler->label($diagram->chain, $solutions) : null,
                'editUrl' => route('diagrams.show', $diagram),
            ]),
        ]);
    }

    /**
     * Both routes into this solution, merged and de-duplicated.
     *
     * `unique('id')` is doing real work on the participants half too: a
     * solution that appears twice in the same chain (a round trip) comes back
     * duplicated by the pivot join.
     *
     * @return Collection<int, Diagram>
     */
    private function diagrams(): Collection
    {
        $columns = ['diagrams.id', 'diagrams.name', 'diagrams.slug', 'diagrams.status', 'diagrams.chain', 'diagrams.viz_layout'];

        // The drawings this solution takes part in — the one relation a diagram
        // has. There used to be a second source here (drawings explained by a
        // page in a caderno linked to this solution), which went away with the
        // page↔diagram FK: a citation lives in prose now, and a card that
        // listed "diagrams mentioned anywhere in the text" would be a LIKE over
        // every page's longText to render a sidebar.
        return $this->solution->diagrams()
            ->get($columns)
            ->sortBy('name', SORT_NATURAL | SORT_FLAG_CASE)
            ->values();
    }
}
