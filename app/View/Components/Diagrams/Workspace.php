<?php

namespace App\View\Components\Diagrams;

use App\Enums\ChainNodeKind;
use App\Enums\Protocol;
use App\Models\Diagram;
use App\Models\Solution;
use App\Support\ChainGraph;
use App\Support\ChainLabeler;
use App\Support\Heroicons;
use Illuminate\Contracts\View\View;
use Illuminate\View\Component;

/**
 * Mounts the F3 chain canvas for a single diagram.
 *
 * Used from two places, unchanged in either: the diagram's own page
 * (`diagrams/show.blade.php`) and the Diagrama tab of any documentation page
 * that points at this diagram. Nothing here knows which one it is — the
 * canvas takes every endpoint it calls from the graph payload
 * (`Diagram::chainUrls()`), so the same drawing is edited identically from
 * wherever it is opened.
 *
 * There's only ever one diagram in scope, so this renders a single hidden row
 * carrying the resolved graph; `chain-select.js` auto-selects it on load,
 * which is what draws the canvas.
 */
class Workspace extends Component
{
    public function __construct(public Diagram $diagram) {}

    public function render(): View
    {
        $labeler = new ChainLabeler;
        $solutions = $labeler->resolveSolutions(collect([$this->diagram->chain]));

        return view('components.diagrams.workspace', [
            'diagram' => $this->diagram,
            'graph'   => ChainGraph::for($this->diagram, $labeler, $solutions),
            // The following three JSON payloads feed the canvas's editors
            // (kind picker, solution select, protocol select) — see
            // `chain/viz.blade.php`'s own docblock. The diagram's own status
            // isn't among them: it's edited in the page's top bar
            // (`Diagrams\Meta`), not on the canvas.
            'solutionsList' => Solution::orderBy('name')->get(['id', 'name']),
            'protocolsList' => collect(Protocol::cases())->map(fn (Protocol $p) => ['value' => $p->value, 'label' => $p->label()])->values(),
            // Only the PICKABLE kinds — `Image` is excluded, since the kind
            // picker cards are never how an image block gets created (pasting
            // a picture on the canvas is, see `ChainNodeKind::pickable()`).
            'kindsList' => collect(ChainNodeKind::cases())->filter(fn (ChainNodeKind $k) => $k->pickable())->map(fn (ChainNodeKind $k) => [
                'value'         => $k->value,
                'label'         => $k->label(),
                'system'        => $k->referencesSolution(),
                'placeholder'   => $k->placeholder(),
                'optionalLabel' => $k->defaultLabel() !== null,
                'icon'          => Heroicons::outlineSvg($k->pickerIcon()),
            ])->values(),
        ]);
    }
}
