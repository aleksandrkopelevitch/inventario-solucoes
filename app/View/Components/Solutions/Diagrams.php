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
 * Plain nav list of the diagrams a solution appears in: name, the caderno it
 * belongs to, chain summary and status, with a delete action. It's the left
 * column of the solution detail page's "diagramas + documentação" card —
 * `Solutions\Documentation` is the right one, and the card's frame lives in
 * `solutions/show.blade.php` since each column is its own updatable slot.
 *
 * "Appears in" means `participants`: a `system` block referencing this
 * solution (derived by `SyncDiagramFromChain`) or a system somebody declared
 * by hand (`SetDiagramSystems`).
 *
 * It no longer CREATES a diagram. The "Novo" form that used to sit here made a
 * drawing that belonged to nothing; a diagram is born inside a caderno now,
 * so each row names the caderno instead, and that is where a new one starts.
 *
 * Each row links straight to the diagram's own canvas page.
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
        $columns = ['diagrams.id', 'diagrams.notebook_id', 'diagrams.name', 'diagrams.slug', 'diagrams.status', 'diagrams.chain', 'diagrams.viz_layout'];

        // The drawings this solution takes part in — the one relation a diagram
        // has. There used to be a second source here (drawings explained by a
        // page in a caderno linked to this solution), which went away with the
        // page↔diagram FK: a citation lives in prose now, and a card that
        // listed "diagrams mentioned anywhere in the text" would be a LIKE over
        // every page's longText to render a sidebar.
        return $this->solution->diagrams()
            ->with('notebook:id,name,slug')
            ->get($columns)
            ->sortBy('name', SORT_NATURAL | SORT_FLAG_CASE)
            ->values();
    }
}
