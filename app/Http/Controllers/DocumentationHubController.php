<?php

namespace App\Http\Controllers;

use App\Models\Solution;
use App\Services\DocumentationCoverageService;
use App\View\Components\Documentation\Hub;
use Illuminate\Contracts\View\View;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * Hub de Documentação — visão gerencial transversal do que está documentado e
 * do que falta, para soluções e integrações (cobertura por conteúdo real).
 * Thin: agregação em DocumentationCoverageService. Mesma action HTML/JSON — o
 * JSON devolve o slot da lista filtrada (padrão dos catálogos).
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
