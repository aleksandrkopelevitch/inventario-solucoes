<?php

namespace App\Actions;

use App\Models\Solution;
use App\Models\SubmissionDiagram;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

/**
 * Removes a solution from the catalog without taking the records around it.
 *
 * Everything that merely LINKS to a solution — its owners, the cadernos that
 * document it, the relationships the documentation and people recorded —
 * cascades on its pivot or row, so the link goes and the person, the caderno
 * and the page stay. A person is a first-class record here, not a child of the
 * solution; so is a company, which the solution points AT
 * (`vendor_company_id`) rather than owning. A UML diagram that bound the
 * solution keeps drawing the element, now bound to nothing.
 *
 * One reference needs work before the row can go: a committee submission's
 * AS IS / TO BE drawing stores `solution_id` beside the `label` a block was
 * drawn with, with no foreign key. Dropping only the id leaves the block where
 * it was, named the same, as free text — what a drawing of a system that left
 * the catalog should look like. Deleting the block instead would renumber
 * every edge after it.
 */
class DeleteSolution
{
    public function handle(Solution $solution): void
    {
        $drawings = $this->drawingsMentioning($solution);

        DB::transaction(function () use ($solution, $drawings) {
            foreach ($drawings as $drawing) {
                $drawing->writeChain(chain: $this->withoutSolution($drawing->chainData() ?? [], $solution));
            }

            $solution->delete();
        });
    }

    /**
     * The same chain, with every block that pointed at this solution turned
     * into free text. The label falls back on the solution's name when the
     * block was stored blank, or it would survive as an unnamed rectangle.
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
     * Every submission drawing whose chain names this solution. Matched in PHP:
     * the chain is a JSON column the two database engines read differently,
     * and a `like '%"solution_id":5%'` would also match solution 50.
     *
     * @return Collection<int, SubmissionDiagram>
     */
    private function drawingsMentioning(Solution $solution): Collection
    {
        $ids = SubmissionDiagram::query()
            ->select(['id', 'chain'])
            ->cursor()
            ->filter(fn (SubmissionDiagram $drawing) => collect($drawing->chain['nodes'] ?? [])
                ->contains(fn (mixed $node) => is_array($node) && ($node['solution_id'] ?? null) === $solution->id))
            ->pluck('id');

        return $ids->isEmpty() ? collect() : SubmissionDiagram::whereKey($ids)->get();
    }
}
