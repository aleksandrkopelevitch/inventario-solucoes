<?php

namespace App\View\Components\Solutions;

use App\Enums\IntegrationStatus;
use App\Models\Integration;
use App\Models\Solution;
use App\View\Components\Concerns\Renderable;
use Illuminate\Contracts\View\View;
use Illuminate\Support\Facades\Gate;
use Illuminate\View\Component;

/**
 * The integration's name and status, in the top bar of its unified
 * documentation+diagram page (`documentation/edit.blade.php`) — the two
 * metadata that don't live in a node or an edge of the chain, so
 * `SyncIntegrationFromChain` never touches them.
 *
 * Both are edited in place (`x-ui.inline-edit`, one PATCH to
 * `solutions.integrations.update`), which is what finally made the status
 * VISIBLE while writing the documentation: until 2026-08-17 it could only be
 * changed from a panel behind a pencil inside the Diagrama canvas — invisible
 * from the Documentação tab, and duplicated state the moment anything else
 * showed a status. That panel is gone; this is the only place either field is
 * edited.
 *
 * Updatable slot: returned by `SolutionIntegrationController::update()`
 * (together with the pages rail, which lists the integration by name).
 */
class IntegrationMeta extends Component
{
    use Renderable;

    public const DOM_ID = 'integration-meta-slot';

    public function __construct(public Solution $solution, public Integration $integration) {}

    public static function slot(Solution $solution, Integration $integration): array
    {
        return (new static($solution, $integration))->toSlot(self::DOM_ID);
    }

    public function render(): View
    {
        $canEdit = Gate::allows('update', $this->integration);

        return view('components.solutions.integration-meta', [
            'domId'         => self::DOM_ID,
            'integration'   => $this->integration,
            'canEdit'       => $canEdit,
            'action'        => $canEdit ? route('solutions.integrations.update', [$this->solution, $this->integration]) : null,
            'statusOptions' => IntegrationStatus::options(),
        ]);
    }
}
