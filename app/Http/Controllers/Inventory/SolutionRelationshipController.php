<?php

namespace App\Http\Controllers\Inventory;

use App\Actions\Catalog\SaveManualRelationship;
use App\Http\Controllers\Controller;
use App\Http\Requests\StoreSolutionRelationshipRequest;
use App\Http\Requests\UpdateSolutionRelationshipRequest;
use App\Models\Solution;
use App\Models\SolutionRelationship;
use App\View\Components\Solutions\Relationships;
use Illuminate\Http\JsonResponse;

/**
 * The solution page's manual interconnections, edited inline in the
 * "Relacionamentos" card: record one (or the override of a documented pair),
 * correct one, remove one. Each answers with the card's slot and nothing else
 * — it is the only thing on the page that changes.
 *
 * `{relationship}` is resolved through `Solution::resolveChildRouteBinding()`:
 * a record that does not involve `{solution}` is a 404.
 */
class SolutionRelationshipController extends Controller
{
    public function store(StoreSolutionRelationshipRequest $request, Solution $solution, SaveManualRelationship $save): JsonResponse
    {
        $save->handle($solution, $request->other(), $request->direction());

        return $this->saved($solution, 'Interconexão salva.');
    }

    public function update(UpdateSolutionRelationshipRequest $request, Solution $solution, SolutionRelationship $relationship, SaveManualRelationship $save): JsonResponse
    {
        $save->handle($solution, $request->other(), $request->direction(), $relationship);

        return $this->saved($solution, 'Interconexão salva.');
    }

    public function destroy(Solution $solution, SolutionRelationship $relationship): JsonResponse
    {
        $this->authorize('update', $solution);

        $relationship->delete();

        return $this->saved($solution, 'Interconexão removida.');
    }

    private function saved(Solution $solution, string $message): JsonResponse
    {
        return response()->json([
            'message'        => $message,
            'updatableSlots' => [Relationships::slot($solution)],
        ]);
    }
}
