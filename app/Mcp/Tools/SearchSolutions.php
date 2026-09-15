<?php

namespace App\Mcp\Tools;

use App\Enums\AttributeGroup;
use App\Mcp\Support\Arguments;
use App\Mcp\Support\Presenter;
use App\Mcp\Support\Vocabulary;
use App\Mcp\Tool;
use App\Mcp\ToolResult;
use App\Models\Solution;

/**
 * The catalog's front door, and the tool a model should reach for first.
 *
 * It is `Solution::scopeFilter()` and nothing else — the same scope the
 * `/solutions` screen, its result counter and its filter chips all read
 * (§ AJAX — Multiple different slots). Re-deriving the conditions here would
 * mean the MCP answering a different question from the screen somebody checks
 * it against, which is the failure that scope exists to prevent. It also buys
 * `whereFolded()` for free: `solucao` finds "Solução" and `big` finds "Google
 * BigQuery", which matters more here than anywhere, since a model types the
 * accents it feels like typing.
 */
class SearchSolutions implements Tool
{
    /**
     * Which argument maps onto which attribute group. `contract` is the odd one
     * — `scopeFilter()` calls it that while the column and the option group are
     * `contract_status` — so the mapping is written out rather than inferred.
     */
    private const FILTERS = [
        'category'    => AttributeGroup::Category,
        'status'      => AttributeGroup::Status,
        'directorate' => AttributeGroup::Directorate,
        'environment' => AttributeGroup::Environment,
        'contract'    => AttributeGroup::ContractStatus,
    ];

    public function __construct(private readonly Presenter $presenter) {}

    public function name(): string
    {
        return 'search_solutions';
    }

    public function title(): string
    {
        return 'Buscar soluções';
    }

    public function description(): string
    {
        return <<<'TXT'
        Busca sistemas e soluções no catálogo por nome, fornecedor ou pessoa responsável,
        com filtros opcionais de categoria, status, diretoria, ambiente e contrato.
        Retorna um resumo de cada solução; use get_solution com o slug retornado para o
        cadastro completo (descrição, responsáveis, diagramas e cadernos publicados).

        Chame sem nenhum argumento para listar o catálogo inteiro. A busca ignora acentos
        e maiúsculas, então "solucao" encontra "Solução".
        TXT;
    }

    public function inputSchema(): array
    {
        return [
            'type'       => 'object',
            'properties' => [
                'query' => [
                    'type'        => 'string',
                    'description' => 'Texto livre: nome da solução, nome da empresa fornecedora ou nome de uma pessoa responsável.',
                ],
                'category'    => Vocabulary::schema(AttributeGroup::Category, 'Categoria da solução.'),
                'status'      => Vocabulary::schema(AttributeGroup::Status, 'Situação da solução.'),
                'directorate' => Vocabulary::schema(AttributeGroup::Directorate, 'Diretoria responsável.'),
                'environment' => Vocabulary::schema(AttributeGroup::Environment, 'Ambiente de hospedagem.'),
                'contract'    => Vocabulary::schema(AttributeGroup::ContractStatus, 'Situação do contrato.'),
                'limit'       => [
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
        $filters = ['search' => $args->string('query')];

        foreach (self::FILTERS as $argument => $group) {
            $given = $args->string($argument);

            if ($given === null) {
                continue;
            }

            $resolved = Vocabulary::resolve($group, $given);

            // Refused rather than ignored. A filter silently dropped answers
            // with the WHOLE catalog and the model reports it as the filtered
            // result — the one failure mode here that produces a confident
            // wrong answer instead of an empty one.
            if ($resolved === null) {
                return ToolResult::failure(
                    "\"{$given}\" não é um valor válido para \"{$argument}\". Use um destes: "
                    . Vocabulary::legend($group) . '.',
                );
            }

            $filters[$argument] = $resolved;
        }

        $limit = $args->int('limit', 25, 1, 100);

        $query = Solution::query()->filter($filters)->with('vendor:id,name');
        $total = $query->count();

        $solutions = $query->orderBy('name')->limit($limit)->get();

        return ToolResult::json([
            'total'    => $total,
            'returned' => $solutions->count(),
            // Said out loud, because a model that sees 25 results and no count
            // reports "existem 25 soluções".
            'truncated' => $total > $solutions->count(),
            'solutions' => $solutions->map(fn (Solution $s) => $this->presenter->solutionSummary($s))->all(),
        ]);
    }
}
