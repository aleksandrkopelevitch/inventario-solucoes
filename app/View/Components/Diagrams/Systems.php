<?php

namespace App\View\Components\Diagrams;

use App\Models\Diagram;
use App\Models\Solution;
use App\View\Components\Concerns\Renderable;
use Illuminate\Contracts\View\View;
use Illuminate\Support\Facades\Gate;
use Illuminate\View\Component;

/**
 * "Sistemas envolvidos" — the drawing's participants, in the top bar of its
 * canvas page, stated in the one place somebody is actually looking at the
 * drawing.
 *
 * It shows BOTH halves of `diagram_solution` and says which is which, because
 * the difference is the only thing that explains why one of them can be removed
 * here and the other cannot: a DRAWN system is a block on the canvas and is
 * unlinked by editing that block, while a DECLARED one exists only as this
 * statement. Hiding the derived ones would make the panel read as the complete
 * list of a diagram's systems while showing a fraction of it.
 *
 * Updatable slot: returned by `DiagramController::syncSystems()`.
 */
class Systems extends Component
{
    use Renderable;

    public const DOM_ID = 'diagram-systems-slot';

    public function __construct(public Diagram $diagram) {}

    public static function slot(Diagram $diagram): array
    {
        return (new static($diagram))->toSlot(self::DOM_ID);
    }

    public function render(): View
    {
        $participants = $this->diagram->participants()->get(['solutions.id', 'solutions.name', 'solutions.slug']);

        // Which rows came from the chain, read off the pivot the derivation
        // wrote — not recomputed from the chain, so the panel reports what the
        // catalog and the map are actually reading.
        [$declared, $drawn] = $participants->partition(fn (Solution $s) => (bool) $s->pivot->manual);

        return view('components.diagrams.systems', [
            'domId'    => self::DOM_ID,
            'diagram'  => $this->diagram,
            'canEdit'  => Gate::allows('update', $this->diagram),
            'action'   => route('diagrams.systems', $this->diagram),
            'drawn'    => $drawn->values(),
            'declared' => $declared->values(),
            'total'    => $participants->count(),
        ]);
    }
}
