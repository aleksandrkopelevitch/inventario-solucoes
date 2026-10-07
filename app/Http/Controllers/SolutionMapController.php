<?php

namespace App\Http\Controllers;

use App\Services\DiagramGraphService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * The ecosystem map (`/map`): every system and who talks to whom, at solution
 * level, in two views — "Por ligações" (the default) and "Por hospedagem",
 * where the systems sit inside one container per cloud / hosting model.
 *
 * No module gate, by decision: the map belongs to no module
 * (App\Enums\AccessModule) and every account reads it, whatever its levels —
 * `auth` on the route is the whole authorization.
 *
 * It carries no filters any more (2026-10-07): the bar above the canvas is the
 * view selector, the system search and nothing else.
 */
class SolutionMapController extends Controller
{
    public const VIEWS = [
        'links'   => 'Por ligações',
        'hosting' => 'Por hospedagem',
    ];

    public function __construct(private readonly DiagramGraphService $graph) {}

    public function index(Request $request)
    {
        if ($request->wantsJson()) {
            return $this->data($request);
        }

        return view('solutions.map', ['views' => self::VIEWS]);
    }

    /**
     * The map's payload. `?view=hosting` adds the systems no diagram names —
     * that view is a picture of where everything runs, connected or not.
     *
     * Read as a plain string or not at all: a query string can make it an
     * array (`?view[]=x`), and the comparison below should answer "links" for
     * that rather than a 500.
     */
    public function data(Request $request): JsonResponse
    {
        $view = is_string($request->query('view')) ? $request->query('view') : 'links';

        return response()->json($this->graph->globalMap(wholeCatalog: $view === 'hosting'));
    }
}
