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

    /**
     * Auto-save da posição de um hub arrastado no canvas (`ecosystem-map.js::
     * startHubDrag`) — sem painel/botão de salvar, dispara a cada arraste
     * solto. Persistida em `solutions.map_position` (global, não por usuário)
     * pra sobreviver a reloads e ser a mesma pra todo mundo que abre o mapa.
     */
    public function updatePosition(UpdateSolutionMapPositionRequest $request, Solution $solution): JsonResponse
    {
        $solution->update([
            'map_position' => $request->validated(),
        ]);

        return response()->json(['message' => 'Posição salva.']);
    }
}
