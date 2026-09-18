<?php

namespace App\Http\Controllers;

use App\Models\AttributeOption;
use App\Models\Solution;
use App\Services\DiagramGraphService;
use App\Services\SolutionGraphService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class SolutionMapController extends Controller
{
    public function __construct(
        private readonly DiagramGraphService $graph,
        private readonly SolutionGraphService $groups,
    ) {}

    /**
     * Global ecosystem map page — renders the graph container that
     * `ecosystem-map.js` draws into (DOM+SVG, radial hub-and-spoke). A
     * `wantsJson()` request short-circuits straight to `data()`'s JSON
     * contract instead of the full page.
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
            // The SOLUTION status vocabulary, for the grouped reading's own
            // status select — the four hardcoded next to it are a DIAGRAM's.
            'statuses'     => AttributeOption::options('status'),
            'axes'         => SolutionGraphService::AXES,
        ]);
    }

    /**
     * Neutral contract for the global map, with filters via query string.
     *
     * `group` switches the READING, not the screen: with it the same blocks
     * come back arranged around a hub per directorate, owner, vendor or
     * category instead of linked to whoever they exchange messages with. The
     * filters apply to both.
     */
    public function data(Request $request): JsonResponse
    {
        $this->authorize('viewAny', Solution::class);

        $filters = [
            'status'      => $request->query('status'),
            'category'    => $request->query('category'),
            'directorate' => $request->query('directorate'),
        ];

        if ($axis = $request->query('group')) {
            return response()->json($this->groups->groupedBy((string) $axis, $filters));
        }

        $graph = $this->graph->globalMap(filters: [
            'status'      => $request->query('status'),
            'category'    => $request->query('category'),
            'directorate' => $request->query('directorate'),
        ]);

        return response()->json($graph);
    }
}
