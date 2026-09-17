<?php

namespace App\Actions\Documentation;

use App\Enums\DiagramStatus;
use App\Enums\Direction;
use App\Models\Diagram;
use App\Models\Solution;
use App\Support\Diagrams\ModelLayout;
use App\Support\Diagrams\ModelSpec;
use App\Support\DiagramSlug;
use App\Support\Fold;
use Illuminate\Support\Collection;

/**
 * A validated spec becomes an ordinary drawing.
 *
 * The sibling of `CreateDiagramFromDraft`, and the same promise: what comes out
 * is a `Diagram` like any other — drawn, renamed, rewired and deleted the same
 * way, and read by the ecosystem map the same way. The model decided the
 * semantics, `ModelLayout` decided the geometry, and this writes the row.
 */
class CreateDiagramFromModel
{
    public function handle(ModelSpec $spec): Diagram
    {
        $built = ModelLayout::build($spec, $this->resolveSolutions($spec));

        $diagram = Diagram::create([
            'name'        => $spec->name,
            'slug'        => DiagramSlug::unique($spec->name),
            'status'      => DiagramStatus::Planned->value,
            'criticality' => 'medium',
            'direction'   => Direction::Unidirectional->value, // re-derived from the chain right below
            'chain'       => $built['chain'],
            'viz_layout'  => $built['viz_layout'],
        ]);

        $diagram->afterChainMutation();

        return $diagram;
    }

    /**
     * Exactly the resolution `CreateDiagramFromDraft` does, and for exactly the
     * same reason: `whereFoldedIs`, never `whereFolded`. A near match would not
     * merely mislabel a block — `SyncDiagramFromChain` derives `participants`
     * from it, and the ecosystem map is a reading of those.
     *
     * @return Collection<string, Solution> keyed by the folded name
     */
    private function resolveSolutions(ModelSpec $spec): Collection
    {
        $names = collect($spec->items())
            ->pluck('solution')
            ->filter(fn (mixed $name) => is_string($name) && trim($name) !== '')
            ->unique()
            ->values();

        if ($names->isEmpty()) {
            return collect();
        }

        $query = Solution::query()->select(['id', 'name']);

        $names->each(fn (string $name, int $i) => $query->whereFoldedIs('name', $name, $i === 0 ? 'and' : 'or'));

        return $query->get()->keyBy(fn (Solution $solution) => Fold::text($solution->name));
    }
}
