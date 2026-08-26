<?php

namespace App\View\Components\Diagrams;

use App\Enums\DiagramStatus;
use App\Models\Diagram;
use App\View\Components\Concerns\Renderable;
use Illuminate\Contracts\View\View;
use Illuminate\Support\Facades\Gate;
use Illuminate\View\Component;

/**
 * The diagram's name and status, in the top bar of its canvas page — the two
 * metadata that don't live in a node or an edge of the chain, so
 * `SyncDiagramFromChain` never touches them.
 *
 * Both are edited in place (`x-ui.inline-edit`, one PATCH to
 * `diagrams.update`), which is what keeps the status VISIBLE while drawing:
 * until 2026-08-17 it could only be changed from a panel behind a pencil
 * inside the canvas — invisible from anywhere else, and duplicated state the
 * moment another screen showed a status. That panel is gone; this is the only
 * place either field is edited.
 *
 * Updatable slot: returned by `DiagramController::update()`.
 */
class Meta extends Component
{
    use Renderable;

    public const DOM_ID = 'diagram-meta-slot';

    public function __construct(public Diagram $diagram) {}

    public static function slot(Diagram $diagram): array
    {
        return (new static($diagram))->toSlot(self::DOM_ID);
    }

    public function render(): View
    {
        $canEdit = Gate::allows('update', $this->diagram);

        return view('components.diagrams.meta', [
            'domId'         => self::DOM_ID,
            'diagram'       => $this->diagram,
            'canEdit'       => $canEdit,
            'action'        => $canEdit ? route('diagrams.update', $this->diagram) : null,
            'statusOptions' => DiagramStatus::options(),
        ]);
    }
}
