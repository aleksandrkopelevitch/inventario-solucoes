<?php

namespace App\Services;

use App\Models\Diagram;
use Illuminate\Support\Collection;

/**
 * The numbers above the diagrams index, and the list under it.
 *
 * Both answers are about the two things that can be missing from a diagram,
 * and they are missing independently — which is exactly why they are counted
 * separately rather than rolled into one "complete" percentage:
 *
 * - **drawn**: the chain has more than its root block. A diagram created and
 *   never opened is one block and no edges, which is an empty canvas, not a
 *   drawing.
 * - **placed**: the drawing names at least one catalog Solution among its
 *   blocks, so the ecosystem map can reach it. A diagram made only of free text
 *   is legitimate but floats free of the inventory, and that is the gap worth
 *   seeing.
 *
 * There used to be an **explained** counter here — "some page points at me" —
 * back when `documentation_pages.diagram_id` existed. Prose cites a drawing in
 * its text now, so the only way to answer it would be a LIKE over every page's
 * `documentation` longText: exactly what the note below says these counters
 * never do.
 *
 * Never called from a controller body or a Blade view; counters are aggregate
 * queries, so neither the chain json nor any page's `documentation` longText is
 * loaded to produce them.
 */
class DiagramCatalogService
{
    /**
     * @return array{
     *     drawn: array{value: int, total: int, percent: float},
     *     placed: array{value: int, total: int, percent: float},
     * }
     */
    public function counters(): array
    {
        $total = Diagram::count();

        return [
            'drawn'  => $this->counter($this->drawnCount(), $total),
            'placed' => $this->counter(Diagram::whereHas('participants')->count(), $total),
        ];
    }

    /**
     * The index's rows, filtered. Each carries what the list shows and nothing
     * more — no chain json beyond what the summary label needs, and the
     * participants eager-loaded so a row can name the systems it touches
     * without a query per row.
     *
     * @param  array<string, mixed>  $filters
     * @return Collection<int, Diagram>
     */
    public function list(array $filters): Collection
    {
        return Diagram::query()
            ->filter($filters)
            ->with(['participants:id,name,slug'])
            ->orderBy('name')
            ->get(['id', 'name', 'slug', 'status', 'chain']);
    }

    /**
     * How many diagrams have anything drawn on them.
     *
     * `chain` is json in both engines this app runs on (Postgres in dev/prod,
     * sqlite in tests) and "more than one node" is not a portable json
     * predicate, so this reads the column and counts in PHP. It is bounded by
     * the number of diagrams (tens), and `select('chain')` keeps it to the one
     * column — a json_array_length()/json_each() branch per driver would cost
     * more to keep correct than this costs to run.
     */
    private function drawnCount(): int
    {
        return Diagram::query()
            ->whereNotNull('chain')
            ->pluck('chain')
            ->filter(fn ($chain) => count(($chain['nodes'] ?? [])) > 1)
            ->count();
    }

    /** @return array{value: int, total: int, percent: float} */
    private function counter(int $value, int $total): array
    {
        return [
            'value'   => $value,
            'total'   => $total,
            'percent' => $total > 0 ? round($value * 100 / $total, 1) : 0.0,
        ];
    }
}
