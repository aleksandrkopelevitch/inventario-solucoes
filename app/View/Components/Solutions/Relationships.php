<?php

namespace App\View\Components\Solutions;

use App\Models\Notebook;
use App\Models\Solution;
use App\Services\SolutionRelationshipService;
use App\View\Components\Concerns\Renderable;
use Illuminate\Contracts\View\View;
use Illuminate\View\Component;

/**
 * "Relacionamentos" — the left column of the solution page's card: every
 * solution this one talks to, as the documentation's UML diagrams say it
 * (SolutionRelationshipService), one row per pair:
 *
 *     [logo] Esta  ──▶  [logo] Outra        (this one calls the other)
 *
 * THIS solution is always on the left, so the arrow reads the same way on
 * every row. A pair known only through an integration platform carries it
 * ("via SAP CPI"). Read-only: a relationship is changed by changing the
 * diagram that states it, which is where "Onde está documentado" leads.
 *
 * The card is a catalog fact and shows to whoever sees the solution. The
 * EVIDENCE — links into cadernos — shows only to whoever may open them
 * (`viewAny` on Notebook), like the documentation column beside it.
 *
 * Replaced `Solutions\Diagrams` (2026-11-26,
 * docs-and-specs/solution-relationships-from-uml.md).
 */
class Relationships extends Component
{
    use Renderable;

    public const DOM_ID = 'solution-relationships-slot';

    public function __construct(public Solution $solution) {}

    public static function slot(Solution $solution): array
    {
        return (new static($solution))->toSlot(self::DOM_ID);
    }

    public function render(): View
    {
        return view('components.solutions.relationships', [
            'domId'         => self::DOM_ID,
            'solution'      => $this->solution,
            'relationships' => app(SolutionRelationshipService::class)->forSolution($this->solution),
            'showEvidence'  => (bool) auth()->user()?->can('viewAny', Notebook::class),
        ]);
    }
}
