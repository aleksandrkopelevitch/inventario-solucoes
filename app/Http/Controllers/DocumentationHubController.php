<?php

namespace App\Http\Controllers;

use App\Models\Solution;
use App\Services\DocumentationCoverageService;
use App\View\Components\Documentation\Hub;
use Illuminate\Contracts\View\View;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * Documentation Hub — the cross-cutting view of what is documented and what is
 * missing, by actual content. It reads cadernos now (one list, grouped by
 * notebook) plus the solutions no caderno covers yet, which is the gap the
 * screen exists to show.
 *
 * It is deliberately NOT the cadernos catalog: `/notebooks` is where you go to
 * work on documentation, `/documentation` is where you go to see where it is
 * missing. Thin — aggregation lives in DocumentationCoverageService. Same
 * HTML/JSON action — JSON returns the filtered list's slot (catalog pattern).
 */
class DocumentationHubController extends Controller
{
    public function index(Request $request, DocumentationCoverageService $coverage): View|JsonResponse
    {
        $this->authorize('viewAny', Solution::class);

        $filters = (array) $request->query('filter', []);

        if ($request->wantsJson()) {
            return response()->json([
                'updatableSlots' => [Hub::slot($filters)],
            ]);
        }

        return view('documentation.index', [
            'filters'  => $filters,
            'counters' => $coverage->counters(),
            'gaps'     => $coverage->undocumentedSolutions(),
        ]);
    }
}
