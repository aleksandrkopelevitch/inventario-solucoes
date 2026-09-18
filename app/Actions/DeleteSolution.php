<?php

namespace App\Actions;

use App\Models\Diagram;
use App\Models\Solution;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

/**
 * Removes a solution from the catalog without taking the records around it.
 *
 * Everything that merely LINKS to a solution — its owners, the cadernos that
 * document it, the diagrams it takes part in — cascades on the pivot, so the
 * link goes and the person, the caderno and the drawing stay. A person is a
 * first-class record here, not a child of the solution; so is a company, which
 * the solution points AT (`vendor_company_id`) rather than owning.
 *
 * Two references need work before the row can go, and they are the same one
 * seen twice:
 *
 * - `diagrams.source_solution_id` / `target_solution_id` cascade, so the
 *   database would delete the whole DRAWING rather than the reference. Both
 *   are derived from the chain, so re-deriving is the fix.
 * - a chain node stores `solution_id` beside the `label` it was drawn with.
 *   Dropping only the id leaves the block where it was, named the same, as
 *   free text — what a drawing of a system that left the catalog should look
 *   like. Deleting the block instead would renumber every edge after it.
 */
class DeleteSolution
{
    public function __construct(private readonly SyncDiagramFromChain $sync) {}

    public function handle(Solution $solution): void
    {
        $drawings = $this->drawingsMentioning($solution);

        DB::transaction(function () use ($solution, $drawings) {
            foreach ($drawings as $diagram) {
                $chain = $diagram->chain ?? [];

                $chain['nodes'] = array_map(function (array $node) use ($solution) {
                    if (($node['solution_id'] ?? null) !== $solution->id) {
                        return $node;
                    }

                    $node['label'] = $node['label'] ?? $solution->name;
                    $node['solution_id'] = null;

                    return $node;
                }, array_values($chain['nodes'] ?? []));

                $diagram->chain = $chain;
                $diagram->save();
                // Re-derives `source_solution_id`/`target_solution_id` (whose
                // cascade is what this is all for) and the participants pivot.
                $this->sync->handle($diagram);
            }

            $solution->delete();
        });
    }

    /**
     * Every drawing that names this solution, in the chain or in the columns
     * derived from it.
     *
     * The match is made in PHP rather than in SQL because the chain is a JSON
     * column and the two engines this runs on disagree about how to look
     * inside one — and a `like '%"solution_id":5%'` that worked on both would
     * still match solution 50. The scan reads two columns, and a solution is
     * deleted by hand, once.
     *
     * @return Collection<int, Diagram>
     */
    private function drawingsMentioning(Solution $solution): Collection
    {
        $ids = Diagram::query()
            ->select(['id', 'chain', 'source_solution_id', 'target_solution_id'])
            ->cursor()
            ->filter(function (Diagram $diagram) use ($solution) {
                if (in_array($solution->id, [$diagram->source_solution_id, $diagram->target_solution_id], true)) {
                    return true;
                }

                return collect($diagram->chain['nodes'] ?? [])
                    ->contains(fn (mixed $node) => is_array($node) && ($node['solution_id'] ?? null) === $solution->id);
            })
            ->pluck('id');

        return $ids->isEmpty() ? collect() : Diagram::whereKey($ids)->get();
    }
}
