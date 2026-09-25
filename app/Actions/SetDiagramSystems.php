<?php

namespace App\Actions;

use App\Enums\ChainNodeKind;
use App\Models\Diagram;
use Illuminate\Support\Facades\DB;

/**
 * The systems somebody declares a drawing concerns, written to the MANUAL half
 * of `diagram_solution`.
 *
 * The other half is derived from the chain (`SyncDiagramFromChain`), and until
 * this existed it was the only half: a diagram reached the catalog, a solution's
 * page and the ecosystem map only by having a `system` block naming that
 * solution. Which is exactly what a generated process or data-flow diagram has
 * none of — those are lanes and neutral steps, so the drawing existed and no
 * system knew about it.
 *
 * Two rules keep the two halves from contradicting each other:
 *
 * - **A system already DRAWN is never written here.** It is a participant
 *   already, and a second row would list it twice; the set this action is given
 *   is filtered against the chain rather than rejected, so the picker can show
 *   the whole list and simply not duplicate what is on the canvas.
 * - **`source`/`target`/`direction` are untouched.** Those describe the flow,
 *   and a declared system is not in one — it has no edges to read a direction
 *   from. Only the chain may say where a flow starts and ends.
 */
class SetDiagramSystems
{
    /**
     * Far past any chain index, so a system that is merely declared sorts after
     * every system actually drawn (`participants` is ordered by this pivot).
     */
    public const POSITION_BASE = 1000;

    /**
     * @param  array<int, int>  $solutionIds  the whole manual set, every time —
     *                                        what isn't sent is removed
     */
    public function handle(Diagram $diagram, array $solutionIds): void
    {
        $drawn = $this->drawnSolutionIds($diagram);

        $wanted = collect($solutionIds)
            ->map(intval(...))
            ->unique()
            ->reject(fn (int $id) => in_array($id, $drawn, true))
            ->values();

        $pivotData = $wanted
            ->mapWithKeys(fn (int $id, int $i) => [$id => [
                'manual'   => true,
                'position' => self::POSITION_BASE + $i,
            ]])
            ->all();

        DB::transaction(function () use ($diagram, $pivotData) {
            // Scoped to the manual rows on BOTH sides: `sync()` on the whole
            // relation would detach every derived participant, which is the
            // chain's answer and not this action's to give.
            $diagram->participants()->wherePivot('manual', true)->detach();

            if ($pivotData !== []) {
                $diagram->participants()->attach($pivotData);
            }
        });

        $diagram->unsetRelation('participants');
    }

    /**
     * The solutions the chain already contributes — read from the chain itself
     * rather than from the pivot, so this is right even when called before the
     * derivation has caught up with a mutation.
     *
     * The `referencesSolution()` check is the same one `SyncDiagramFromChain`
     * makes, and it has to be: a `solution_id` left on a decision or actor node
     * by an older chain is NOT a participant there, so treating it as drawn
     * here would refuse to declare a system that nothing else lists either.
     *
     * @return array<int, int>
     */
    private function drawnSolutionIds(Diagram $diagram): array
    {
        return collect($diagram->chain['nodes'] ?? [])
            ->filter(fn (mixed $node) => is_array($node) && ChainNodeKind::fromNode($node)->referencesSolution())
            ->pluck('solution_id')
            ->filter(fn (mixed $id) => is_numeric($id))
            ->map(intval(...))
            ->unique()
            ->values()
            ->all();
    }
}
