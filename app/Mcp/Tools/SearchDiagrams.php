<?php

namespace App\Mcp\Tools;

use App\Enums\DiagramStatus;
use App\Mcp\Support\Arguments;
use App\Mcp\Support\Presenter;
use App\Mcp\Support\Vocabulary;
use App\Mcp\Tool;
use App\Mcp\ToolResult;
use App\Models\Diagram;
use App\Models\Solution;

/**
 * The drawings, searchable by name or by which system they touch.
 *
 * The `solution` filter is the one worth explaining: it reads the
 * `diagram_solution` pivot, which is DERIVED — `SyncDiagramFromChain` writes it
 * after every mutation of a `chain`, and nothing else may (§ Diagram topology
 * invariant). So "quais diagramas passam pelo SAP" is answered by the drawings
 * themselves rather than by somebody's filing, and a block added to a canvas
 * this morning is already an answer here.
 */
class SearchDiagrams implements Tool
{
    public function __construct(private readonly Presenter $presenter) {}

    public function requiresInventory(): bool
    {
        // Catálogo: fechado para quem não lê o inventário no app.
        return true;
    }

    public function name(): string
    {
        return 'search_diagrams';
    }

    public function title(): string
    {
        return 'Buscar diagramas';
    }

    public function description(): string
    {
        return <<<'TXT'
        Busca diagramas (desenhos de fluxo/topologia) por nome, ou lista os diagramas de
        que uma solução participa. Retorna um resumo; use get_diagram com o slug para ver
        os blocos, as ligações e os protocolos do desenho.

        Chame sem argumentos para listar todos os diagramas.
        TXT;
    }

    public function inputSchema(): array
    {
        return [
            'type'       => 'object',
            'properties' => [
                'query' => [
                    'type'        => 'string',
                    'description' => 'Texto livre buscado no nome do diagrama.',
                ],
                'solution' => [
                    'type'        => 'string',
                    'description' => 'Slug de uma solução: retorna apenas os diagramas em que ela aparece como bloco.',
                ],
                'status' => [
                    'type'        => 'string',
                    'description' => 'Situação do diagrama. Valores: ' . Vocabulary::enumLegend(DiagramStatus::class) . '.',
                    'enum'        => array_column(DiagramStatus::cases(), 'value'),
                ],
                'limit' => [
                    'type'        => 'integer',
                    'description' => 'Máximo de resultados (1–100, padrão 25).',
                    'default'     => 25,
                ],
            ],
            'required' => [],
        ];
    }

    public function handle(array $arguments): ToolResult
    {
        $args = new Arguments($arguments);

        $status = null;

        if ($given = $args->string('status')) {
            // Resolved rather than passed through, so "Ativo" (what every
            // payload from this server prints) filters as well as `active`.
            $status = Vocabulary::enumValue(DiagramStatus::class, $given);

            if ($status === null) {
                return ToolResult::failure(
                    "\"{$given}\" não é um status de diagrama. Use um destes: "
                    . Vocabulary::enumLegend(DiagramStatus::class) . '.',
                );
            }
        }

        $query = Diagram::query()->filter([
            'search' => $args->string('query'),
            'status' => $status,
        ]);

        if ($solutionSlug = $args->string('solution')) {
            $solution = Solution::query()->where('slug', $solutionSlug)->first(['id', 'name']);

            if (! $solution) {
                return ToolResult::failure(
                    "Nenhuma solução com o slug \"{$solutionSlug}\". Use search_solutions para encontrar o slug correto.",
                );
            }

            $query->whereHas('participants', fn ($q) => $q->whereKey($solution->getKey()));
        }

        $total = $query->count();
        $limit = $args->int('limit', 25, 1, 100);
        $diagrams = $query->orderBy('name')->limit($limit)->get();

        return ToolResult::json([
            'total'     => $total,
            'returned'  => $diagrams->count(),
            'truncated' => $total > $diagrams->count(),
            'diagrams'  => $diagrams->map(fn (Diagram $d) => $this->presenter->diagramSummary($d))->all(),
        ]);
    }
}
