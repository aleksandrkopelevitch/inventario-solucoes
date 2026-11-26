<?php

namespace App\Actions\Catalog;

use App\Enums\ManualRelationshipDirection;
use App\Enums\RelationshipDirection;
use App\Models\Solution;
use App\Models\SolutionRelationship;

/**
 * Records — or corrects — the interconnection between `$solution` and
 * `$other`, with `$direction` read from `$solution`'s side. One record per
 * pair: an existing one is updated, never doubled. Over a pair the
 * documentation already states, the record is the OVERRIDE (it wins on read,
 * SolutionRelationshipService), and removing it brings the documented answer
 * back.
 *
 * `$record` is the row being edited when the solution page re-points one at
 * another counterpart; the caller has already refused a pair that would
 * collide with a third record (the request's uniqueness rule, the MCP tool's
 * lookup).
 *
 * Shared by the solution page (SolutionRelationshipController) and the MCP
 * (`set_solution_relationship`), like the other `Save*` catalog actions.
 */
class SaveManualRelationship
{
    public function handle(Solution $solution, Solution $other, RelationshipDirection $direction, ?SolutionRelationship $record = null): SolutionRelationship
    {
        $a = min($solution->getKey(), $other->getKey());
        $b = max($solution->getKey(), $other->getKey());

        $record ??= SolutionRelationship::query()->forPair($a, $b)->first() ?? new SolutionRelationship;

        $record->fill([
            'solution_a_id' => $a,
            'solution_b_id' => $b,
            'direction'     => ManualRelationshipDirection::fromSide($direction, $solution->getKey() === $a),
        ])->save();

        return $record;
    }
}
