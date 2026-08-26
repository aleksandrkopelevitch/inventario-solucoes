<?php

namespace App\View\Components\Submissions;

use App\Enums\ChainNodeKind;
use App\Enums\Protocol;
use App\Models\Solution;
use App\Models\SubmissionDiagram;
use App\Support\ChainGraph;
use App\Support\ChainLabeler;
use App\Support\Heroicons;
use Illuminate\Contracts\View\View;
use Illuminate\View\Component;

/**
 * Mounts the F3 canvas for one of a submission's drawings.
 *
 * Deliberately the same shape as `Diagrams\Workspace`, down to the
 * single hidden row `chain-select.js` auto-selects on load: the canvas
 * has one mount contract, and a submission's AS IS / TO BE is that contract
 * pointed at a different `ChainCanvas`. Nothing in `chain-viz.js` knows
 * this component exists — the endpoints it calls all arrive inside the graph
 * payload below.
 */
class DiagramWorkspace extends Component
{
    public function __construct(public SubmissionDiagram $diagram) {}

    public function render(): View
    {
        $labeler = new ChainLabeler;
        $solutions = $labeler->resolveSolutions(collect([$this->diagram->chain]));

        return view('components.submissions.diagram-workspace', [
            'diagram' => $this->diagram,
            'graph'   => ChainGraph::for($this->diagram, $labeler, $solutions),

            // The three payloads the canvas's editors read (kind picker,
            // Solution autocomplete, protocol suggestions). Same lists the
            // diagram workspace renders — a proposal draws with the same
            // vocabulary the catalog does, which is the point of reusing the
            // canvas rather than building a second one.
            'solutionsList' => Solution::orderBy('name')->get(['id', 'name']),
            'protocolsList' => collect(Protocol::cases())->map(fn (Protocol $p) => ['value' => $p->value, 'label' => $p->label()])->values(),
            'kindsList'     => collect(ChainNodeKind::cases())
                ->filter(fn (ChainNodeKind $k) => $k->pickable())
                ->map(fn (ChainNodeKind $k) => [
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
