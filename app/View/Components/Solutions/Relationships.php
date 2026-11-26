<?php

namespace App\View\Components\Solutions;

use App\Enums\RelationshipDirection;
use App\Models\Notebook;
use App\Models\Solution;
use App\Services\SolutionRelationshipService;
use App\Support\Relationships\Relationship;
use App\View\Components\Concerns\Renderable;
use Illuminate\Contracts\View\View;
use Illuminate\View\Component;

/**
 * "Relacionamentos" — the left column of the solution page's card: every
 * solution this one talks to, one row per pair (SolutionRelationshipService):
 *
 *     [logo] Esta  ──▶  [logo] Outra        (this one calls the other)
 *
 * THIS solution is always on the left, so the arrow reads the same way on
 * every row. A pair comes from the documentation's UML diagrams, from an
 * interconnection a person recorded here ("manual"), or from both — the
 * record then overrides the documentation ("ajustado manualmente"). A pair
 * known only through an integration platform carries it ("via SAP CPI").
 *
 * Edited inline (`.claude/rules/inline-edit-pattern.md`) by a catalog Editor:
 * "+ Adicionar interconexão" records one; the pencil on a manual row corrects
 * its counterpart and direction; the pencil on a documented row records the
 * override (direction only — the counterpart is what the diagram says); the ✕
 * removes a record, which on an override brings the documentation back. A
 * documented-only row has no ✕: it is removed by editing the diagram, which
 * "Onde está documentado" leads to.
 *
 * The card is a catalog fact and shows to whoever sees the solution. The
 * EVIDENCE — links into cadernos — shows only to whoever may open them
 * (`viewAny` on Notebook), like the documentation column beside it.
 *
 * Updatable slot: every SolutionRelationshipController response answers with
 * it, and with nothing else.
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
        $relationships = app(SolutionRelationshipService::class)->forSolution($this->solution);
        $canEdit = (bool) auth()->user()?->can('update', $this->solution);

        // A counterpart that already has a record is edited on its row, never
        // recorded twice — the one-per-pair rule the request enforces too.
        $recorded = $relationships->filter(fn (Relationship $r) => $r->manual !== null)->map(fn (Relationship $r) => $r->other->getKey())->all();
        $others = $canEdit
            ? Solution::query()->whereKeyNot($this->solution->getKey())->orderBy('name')->get(['id', 'name'])
            : collect();

        return view('components.solutions.relationships', [
            'domId'         => self::DOM_ID,
            'solution'      => $this->solution,
            'relationships' => $relationships,
            'showEvidence'  => (bool) auth()->user()?->can('viewAny', Notebook::class),
            'canEdit'       => $canEdit,
            'freeOptions'   => $others->reject(fn (Solution $s) => in_array($s->getKey(), $recorded, true))
                ->map(fn (Solution $s) => ['value' => $s->getKey(), 'label' => $s->name])
                ->values()
                ->all(),
            'directionOptions' => self::directionChoices(),
        ]);
    }

    /**
     * The three directions a person may record, worded from THIS solution —
     * the left side of every row ("esta").
     *
     * Not named `directionOptions`: a component's PUBLIC METHODS are handed to
     * its view as variables too, and one named like a view key silently wins —
     * the selects rendered with no option at all.
     *
     * @return list<array{value: string, label: string}>
     */
    public static function directionChoices(): array
    {
        // "esta" rather than the name: the option sits in a 13rem select
        // beside the name it would repeat, and a long name cut the sentence.
        return [
            ['value' => RelationshipDirection::Outgoing->value, 'label' => '→ esta chama a outra'],
            ['value' => RelationshipDirection::Incoming->value, 'label' => '← a outra chama esta'],
            ['value' => RelationshipDirection::Both->value, 'label' => '⟷ chamam uma à outra'],
        ];
    }
}
