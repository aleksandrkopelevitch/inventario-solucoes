<?php

namespace App\View\Components\Concerns;

use App\Contracts\ChainCanvas;
use App\Enums\ChainNodeKind;
use App\Enums\Protocol;
use App\Models\Solution;
use App\Support\ChainGraph;
use App\Support\ChainLabeler;
use App\Support\Heroicons;
use Illuminate\Contracts\View\View;
use Illuminate\View\Component;

/**
 * The F3 canvas mounted for one drawing — a catalog `Diagram` or one of a
 * submission's drawings. Both used to build the same three payloads and the
 * same markup in two classes and two views; what differs is the four members
 * below, and the markup is `components.chain.workspace`.
 *
 * The canvas itself needs nothing per owner: every endpoint it calls arrives
 * in the graph (`ChainGraph::for()` takes the `ChainCanvas` both models
 * implement).
 */
abstract class ChainWorkspace extends Component
{
    abstract protected function canvas(): ChainCanvas;

    /** Unique on the page; nothing reads it back. */
    abstract protected function rowKey(): string;

    /** What the canvas's empty state names. */
    abstract protected function name(): string;

    /** Only a catalog diagram has one. */
    protected function status(): ?string
    {
        return null;
    }

    public function render(): View
    {
        $canvas = $this->canvas();
        $labeler = new ChainLabeler;
        $solutions = $labeler->resolveSolutions(collect([$canvas->chainData()]));

        return view('components.chain.workspace', [
            'rowKey' => $this->rowKey(),
            'name'   => $this->name(),
            'status' => $this->status(),
            'graph'  => ChainGraph::for($canvas, $labeler, $solutions),

            // The three payloads the canvas's editors read (kind picker,
            // Solution select, protocol suggestions) — the same vocabulary for a
            // proposal as for the catalog, which is the point of one canvas. A
            // diagram's own status isn't among them: it's edited in the page's
            // top bar (`Diagrams\Meta`), not on the canvas.
            'solutionsList' => Solution::orderBy('name')->get(['id', 'name']),
            'protocolsList' => collect(Protocol::cases())->map(fn (Protocol $p) => ['value' => $p->value, 'label' => $p->label()])->values(),
            // Only the PICKABLE kinds — `Image` is excluded, since pasting a
            // picture on the canvas is how an image block is created
            // (`ChainNodeKind::pickable()`).
            'kindsList' => collect(ChainNodeKind::cases())
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
