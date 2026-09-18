<?php

namespace App\Actions;

use App\Contracts\ChainCanvas;
use App\Models\ApprovedTopology;
use App\Models\Diagram;
use App\Models\Solution;
use App\Models\SubmissionDiagram;
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
    /**
     * @return array{drawings: int, topologies: int} what the caller should say
     *                                               out loud — see below
     */
    public function handle(Solution $solution): array
    {
        $drawings = $this->drawingsMentioning($solution);
        // `approved_topologies.solution_id` is NOT NULL and cascades, so a
        // committee's approval goes with the solution and there is no way to
        // keep it — an approval to apply a topology TO this solution means
        // nothing once the solution is gone. What there IS a way to avoid is
        // doing it in silence, which is why the count comes back.
        $topologies = ApprovedTopology::where('solution_id', $solution->id)->count();

        DB::transaction(function () use ($solution, $drawings) {
            foreach ($drawings as $canvas) {
                $canvas->writeChain(chain: $this->sweptChain($canvas->chainData(), $solution));
                // A catalog drawing re-derives `source_solution_id` /
                // `target_solution_id` here — the cascade this is all for —
                // and its participants pivot. A submission's drawing derives
                // nothing, and says so with an empty body.
                $canvas->afterChainMutation();
            }

            // The THIRD store of `{solution_id, label, kind}` nodes, and the one
            // with no owner sweeping it: an approved topology's chain is a
            // snapshot, and `ApprovedTopology` is not a `ChainCanvas`. The rows
            // approved FOR this solution cascade away with it; one approved for
            // ANOTHER solution whose chain happens to name this one survives,
            // and applying it later writes the dead id straight into
            // `diagram_solution` through `SyncDiagramFromChain` — a foreign key
            // violation the admin meets as a 500, arbitrarily long after the
            // delete that caused it.
            foreach ($this->topologiesNaming($solution) as $topology) {
                $topology->chain = $this->sweptChain($topology->chain, $solution);
                $topology->save();
            }

            $solution->delete();
        });

        return ['drawings' => $drawings->count(), 'topologies' => $topologies];
    }

    /**
     * One chain with every mention of this solution turned into free text.
     *
     * @param  array<mixed>|null  $chain
     * @return array<mixed>
     */
    private function sweptChain(?array $chain, Solution $solution): array
    {
        $chain ??= [];

        $chain['nodes'] = array_map(function (array $node) use ($solution) {
            if (($node['solution_id'] ?? null) !== $solution->id) {
                return $node;
            }

            $node['label'] = $node['label'] ?? $solution->name;
            $node['solution_id'] = null;

            return $node;
        }, array_values($chain['nodes'] ?? []));

        return $chain;
    }

    /**
     * Approved topologies that NAME this solution without belonging to it. The
     * ones that belong to it are not here: their foreign key cascades, and an
     * approval to apply a topology to a solution that is gone means nothing.
     *
     * @return Collection<int, ApprovedTopology>
     */
    private function topologiesNaming(Solution $solution): Collection
    {
        return ApprovedTopology::query()
            ->where('solution_id', '!=', $solution->id)
            ->get()
            ->filter(fn (ApprovedTopology $topology) => collect($topology->chain['nodes'] ?? [])
                ->contains(fn (mixed $node) => is_array($node) && ($node['solution_id'] ?? null) === $solution->id));
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
     * @return Collection<int, ChainCanvas>
     */
    private function drawingsMentioning(Solution $solution): Collection
    {
        return $this->canvasesMentioning($solution, Diagram::class, ['source_solution_id', 'target_solution_id'])
            ->concat($this->canvasesMentioning($solution, SubmissionDiagram::class, []));
    }

    /**
     * The same scan against either owner of a chain.
     *
     * A CATI submission's AS IS / TO BE is the SAME canvas with a different
     * owner — both implement `ChainCanvas` — and stores the same
     * `{solution_id, label, kind}` node. It has no foreign key to this row, so
     * nothing cascades and nothing complained; the node simply went on
     * pointing at a solution that no longer exists, which `ChainLabeler`
     * renders as `?`. Sweeping only `diagrams` left exactly that.
     *
     * @param  class-string<ChainCanvas&\Illuminate\Database\Eloquent\Model>  $model
     * @param  list<string>  $derived  columns holding the same reference
     * @return Collection<int, ChainCanvas>
     */
    private function canvasesMentioning(Solution $solution, string $model, array $derived): Collection
    {
        $ids = $model::query()
            ->select(array_merge(['id', 'chain'], $derived))
            ->cursor()
            ->filter(function (ChainCanvas $canvas) use ($solution, $derived) {
                foreach ($derived as $column) {
                    if ($canvas->{$column} === $solution->id) {
                        return true;
                    }
                }

                return collect($canvas->chainData()['nodes'] ?? [])
                    ->contains(fn (mixed $node) => is_array($node) && ($node['solution_id'] ?? null) === $solution->id);
            })
            ->pluck('id');

        return $ids->isEmpty() ? collect() : $model::whereKey($ids)->get();
    }
}
