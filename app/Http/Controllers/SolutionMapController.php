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

        // `statuses` is the SOLUTION vocabulary, and it is here because the two
        // readings cannot share one option list: the topology map filters the
        // diagrams it draws as edges (`DiagramStatus`), the grouped reading has
        // no diagrams at all and filters the solutions. The two only look
        // alike — a solution is never `in_development`, a diagram is never
        // `evaluating` — so one shared list made the control lie.
        return view('solutions.map', [
            'categories'   => AttributeOption::options('category'),
            'directorates' => AttributeOption::options('directorate'),
            'axes'         => SolutionGraphService::AXES,
            'statuses'     => AttributeOption::options('status'),
        ]);
    }

    /**
     * Neutral contract for the global map, with filters via query string.
     *
     * `group` switches the READING, not the screen: with it the same blocks
     * come back arranged around a hub per directorate, owner, vendor or
     * category instead of linked to whoever they exchange messages with.
     *
     * One `$filters` array, passed to both — it used to be two identical
     * literals, which is a drift waiting for the fourth filter. `category` and
     * `directorate` mean the same thing on either side; `status` does NOT, and
     * that is a property of the readings rather than of this method: the
     * topology map filters the DIAGRAMS it draws as edges, the grouped reading
     * filters the SOLUTIONS, and the view offers each its own vocabulary.
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

        return response()->json($this->graph->globalMap(filters: $filters));
    }
}
