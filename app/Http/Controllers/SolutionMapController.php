<?php

namespace App\Http\Controllers;

use App\Models\AttributeOption;
use App\Models\Solution;
use App\Services\IntegrationGraphService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class SolutionMapController extends Controller
{
    public function __construct(private readonly IntegrationGraphService $graph) {}

    /**
     * Página do mapa global (F3). Renderiza o container do grafo; o desenho no
     * canvas entra na Etapa 4. Aceita `?json` para servir o próprio contrato.
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

    /** Contrato neutro do mapa global, com filtros por query string. */
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
