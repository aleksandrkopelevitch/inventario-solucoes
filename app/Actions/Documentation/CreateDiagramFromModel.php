<?php

namespace App\Actions\Documentation;

use App\Actions\WriteNotebookDiagram;
use App\Models\Diagram;
use App\Models\Notebook;
use App\Models\Solution;
use App\Support\Diagrams\ModelLayout;
use App\Support\Diagrams\ModelSpec;
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
    public function __construct(private readonly WriteNotebookDiagram $writer) {}

    /**
     * @param  string|null  $name  the name the author typed; the spec's own is only the fallback
     * @param  Diagram|null  $target  the caderno's diagram to draw over, instead of creating one
     */
    public function handle(ModelSpec $spec, Notebook $notebook, ?string $name = null, ?Diagram $target = null): Diagram
    {
        $built = ModelLayout::build($spec, $this->resolveSolutions($spec));

        // Named for the model it IS — see `DiagramModel::suffixed()`. The slug
        // is derived from the same string, so the address says it too. Applied
        // to a typed name as well: the dialog prefills it already suffixed, and
        // `suffixed()` refuses to add a suffix that is already there. An
        // overwrite keeps the target's name, whatever the model.
        $name = $spec->model->suffixed(trim((string) $name) ?: $spec->name);

        return $this->writer->handle(
            $notebook,
            chain: $built['chain'],
            layout: $built['viz_layout'],
            name: $name,
            target: $target,
        );
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
