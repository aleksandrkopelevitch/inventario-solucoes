<?php

namespace App\Http\Controllers;

use App\Models\AttributeOption;
use App\Models\Solution;
use App\Services\DiagramGraphService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class SolutionMapController extends Controller
{
    public function __construct(private readonly DiagramGraphService $graph) {}

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
}
