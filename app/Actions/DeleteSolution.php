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
 *
 * **Three tables store that node shape, not two.** `approved_topologies.chain`
 * is a SNAPSHOT of a committee's TO BE, so it is not a `ChainCanvas` (nothing
 * draws or edits it, and the contract's media/URL half would be dead weight) —
 * but it holds the same `{solution_id, label, kind}` and its own foreign key
 * only covers the solution the submission was ABOUT. A solution that merely
 * APPEARS inside an approved chain used to survive the delete as a dangling
 * id, and `ApplyApprovedTopology` then died on it: `SyncDiagramFromChain`
 * attaches the participants it reads from the chain, so the apply answered
 * `FOREIGN KEY constraint failed` on `diagram_solution` — a 500 for whoever
 * approved it, months after the delete that caused it.
 */
class DeleteSolution
{
    /**
     * @return int how many approved topologies went with it — see below
     */
    public function handle(Solution $solution): int
    {
        $drawings = $this->drawingsMentioning($solution);
        $snapshots = $this->snapshotsMentioning($solution);

        // `approved_topologies.solution_id` is NOT NULL and cascades, so a row
        // approved FOR this solution cannot be kept: an approval to apply a
        // topology TO a system means nothing once the system is gone. What it
        // can stop doing is going in silence — a committee decision disappears
        // here, and the count is what lets the Toast say so. (These are NOT the
        // `$snapshots` above: those are approvals for OTHER solutions whose
        // chain merely names this one, and they survive, swept.)
        $cascading = ApprovedTopology::where('solution_id', $solution->id)->count();

        DB::transaction(function () use ($solution, $drawings, $snapshots) {
            foreach ($drawings as $canvas) {
                $canvas->writeChain(chain: $this->withoutSolution($canvas->chainData() ?? [], $solution));
                // A catalog drawing re-derives `source_solution_id` /
                // `target_solution_id` here — the cascade this is all for —
                // and its participants pivot. A submission's drawing derives
                // nothing, and says so with an empty body.
                $canvas->afterChainMutation();
            }

            // A snapshot derives nothing at rest: `ApplyApprovedTopology`
            // writes it into a `Diagram` and lets THAT re-derive. So the chain
            // is the whole record here, and rewriting it is the whole fix.
            foreach ($snapshots as $snapshot) {
                $snapshot->update(['chain' => $this->withoutSolution($snapshot->chain ?? [], $solution)]);
            }

            $solution->delete();
        });

        return $cascading;
    }

    /**
     * The same chain, with every block that pointed at this solution turned
     * into free text.
     *
     * `is_array` only so this method agrees with `canvasesMentioning()` below,
     * which already guards it when deciding whether a chain matches — the two
     * halves of one scan disagreeing about the shape they walk is its own bug.
     * It does NOT make a malformed chain survivable: `SyncDiagramFromChain`
     * type-hints the same node `array` and dies on it a moment later, and the
     * dev corpus holds 33 nodes and no such entry. Don't read this guard as a
     * promise the rest of the pipeline does not make.
     *
     * The label falls back on blank as well as null, and that half is real: a
     * block stored with `''` survived as an unnamed rectangle, which is the
     * outcome this method exists to avoid.
     *
     * @param  array<string, mixed>  $chain
     * @return array<string, mixed>
     */
    private function withoutSolution(array $chain, Solution $solution): array
    {
        $chain['nodes'] = array_map(function (mixed $node) use ($solution) {
            if (! is_array($node) || ($node['solution_id'] ?? null) !== $solution->id) {
                return $node;
            }

            $node['label'] = ($node['label'] ?? '') !== '' ? $node['label'] : $solution->name;
            $node['solution_id'] = null;

            return $node;
        }, array_values($chain['nodes'] ?? []));

        return $chain;
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
     * Every approved TO BE whose snapshot names this solution.
     *
     * Scanned in PHP for the same reason the drawings are, and kept apart from
     * them because an `ApprovedTopology` is not a canvas: it has no
     * `afterChainMutation()` to run and no media to keep, so the contract the
     * other two share would be a costume on this one.
     *
     * Applied rows are swept too. An applied snapshot is history, but
     * `ApplyApprovedTopology::handle()` takes an optional target and can write
     * it again into a different diagram — history that can still be replayed
     * has to be replayable.
     *
     * @return Collection<int, ApprovedTopology>
     */
    private function snapshotsMentioning(Solution $solution): Collection
    {
        $ids = ApprovedTopology::query()
            ->select(['id', 'chain'])
            ->cursor()
            ->filter(fn (ApprovedTopology $topology) => $this->chainMentions($topology->chain ?? [], $solution))
            ->pluck('id');

        return $ids->isEmpty() ? collect() : ApprovedTopology::whereKey($ids)->get();
    }

    /** Whether any node in this chain points at the solution. */
    private function chainMentions(?array $chain, Solution $solution): bool
    {
        return collect($chain['nodes'] ?? [])
            ->contains(fn (mixed $node) => is_array($node) && ($node['solution_id'] ?? null) === $solution->id);
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

                return $this->chainMentions($canvas->chainData() ?? [], $solution);
            })
            ->pluck('id');

        return $ids->isEmpty() ? collect() : $model::whereKey($ids)->get();
    }
}
