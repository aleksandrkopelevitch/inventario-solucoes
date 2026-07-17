<?php

namespace App\Http\Controllers;

use App\Models\Solution;
use App\Services\DocumentationCoverageService;
use App\View\Components\Documentation\Hub;
use Illuminate\Contracts\View\View;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * Documentation Hub — cross-cutting management view of what is documented
 * and what's missing, for solutions and integrations (coverage by actual
 * content). Thin: aggregation lives in DocumentationCoverageService. Same
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
        ]);
    }
}
