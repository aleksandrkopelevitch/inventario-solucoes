<?php

namespace App\View\Components\Solutions;

use App\Enums\ChainNodeKind;
use App\Enums\IntegrationStatus;
use App\Enums\Protocol;
use App\Models\Integration;
use App\Models\Solution;
use App\Support\ChainLabeler;
use App\Support\Heroicons;
use Illuminate\Contracts\View\View;
use Illuminate\View\Component;

/**
 * Mounts the F3 chain canvas for a single integration — the Diagrama tab of
 * the integration's unified documentation+diagram page (see
 * `IntegrationDocumentationController`). Replaces the old solution-detail
 * rail+canvas split: there's only ever one integration in scope here, so
 * this renders the same JSON payloads (kinds/solutions/protocols/statuses)
 * `integration-viz.js` expects to find on the page, plus a single hidden row
 * carrying the resolved graph. `integration-select.js` auto-selects that
 * lone row on load (nothing else to choose from), which draws the canvas the
 * same way clicking a row used to on the old rail.
 */
class IntegrationWorkspace extends Component
{
    public function __construct(public Solution $solution, public Integration $integration) {}

    public function render(): View
    {
        $labeler = new ChainLabeler;
        $solutions = $labeler->resolveSolutions(collect([$this->integration->chain]));

        return view('components.solutions.integration-workspace', [
            'solution'    => $this->solution,
            'integration' => $this->integration,
            'graph'       => (new IntegrationsMap($this->solution))->graph($this->integration, $labeler, $solutions),
            // The following four JSON payloads feed the canvas's editors
            // (kind picker, solution select, protocol select, status select)
            // — see `integration-viz.blade.php`'s own docblock. Same shape as
            // the old rail used to provide.
            'solutionsList' => Solution::orderBy('name')->get(['id', 'name']),
            'protocolsList' => collect(Protocol::cases())->map(fn (Protocol $p) => ['value' => $p->value, 'label' => $p->label()])->values(),
            'statusesList'  => collect(IntegrationStatus::cases())->map(fn (IntegrationStatus $s) => ['value' => $s->value, 'label' => $s->label()])->values(),
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
