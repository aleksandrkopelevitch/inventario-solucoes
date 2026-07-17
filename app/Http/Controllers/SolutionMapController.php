<?php

namespace App\Http\Controllers;

use App\Http\Requests\UpdateSolutionMapPositionRequest;
use App\Models\AttributeOption;
use App\Models\Solution;
use App\Services\IntegrationGraphService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class SolutionMapController extends Controller
{
    public function __construct(private readonly IntegrationGraphService $graph) {}

    /**
     * Global map page (F3). Renders the graph container; the canvas drawing
     * lands in Stage 4. Accepts `?json` to serve the contract itself.
     */
    public function index(Request $request)
    {
        $this->authorize('viewAny', Solution::class);

        if ($request->wantsJson()) {
            return $this->data($request);
        }

        return view('solutions.map', [
            'categories'   => AttributeOption::options('category'),
            'directorates' => AttributeOption::options('directorate'),
        ]);
    }

    /** Neutral contract for the global map, with filters via query string. */
    public function data(Request $request): JsonResponse
    {
        $this->authorize('viewAny', Solution::class);

        $graph = $this->graph->globalMap(filters: [
            'status'      => $request->query('status'),
            'category'    => $request->query('category'),
            'directorate' => $request->query('directorate'),
        ]);

        return response()->json($graph);
    }

    /**
     * Auto-save of a hub's position dragged on the canvas
     * (`ecosystem-map.js::startHubDrag`) — no panel/save button, fires on
     * every drag release. Persisted in `solutions.map_position` (global, not
     * per-user) so it survives reloads and is the same for everyone who
     * opens the map.
     */
    public function updatePosition(UpdateSolutionMapPositionRequest $request, Solution $solution): JsonResponse
    {
        $solution->update([
            'map_position' => $request->validated(),
        ]);

        return response()->json(['message' => 'Posição salva.']);
    }
}
