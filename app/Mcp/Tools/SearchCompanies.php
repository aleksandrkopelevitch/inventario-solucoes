<?php

namespace App\Mcp\Tools;

use App\Enums\CompanyKind;
use App\Mcp\Support\Arguments;
use App\Mcp\Support\Presenter;
use App\Mcp\Support\Vocabulary;
use App\Mcp\Tool;
use App\Mcp\ToolResult;
use App\Models\Company;

/**
 * The companies catalog — fornecedores, integradores, and whoever else the
 * inventory records as being on the other end of a contract.
 */
class SearchCompanies implements Tool
{
    public function __construct(private readonly Presenter $presenter) {}

    public function name(): string
    {
        return 'search_companies';
    }

    public function title(): string
    {
        return 'Buscar empresas';
    }

    public function description(): string
    {
        return <<<'TXT'
        Busca empresas pelo nome. Retorna um resumo; use get_company com o slug para ver
        as pessoas de contato e as soluções que a empresa fornece.

        Chame sem argumentos para listar todas.
        TXT;
    }

    public function inputSchema(): array
    {
        return [
            'type'       => 'object',
            'properties' => [
                'query' => ['type' => 'string', 'description' => 'Texto livre buscado no nome da empresa.'],
                'kind'  => [
                    'type'        => 'string',
                    'description' => 'Tipo de empresa. Valores: ' . Vocabulary::enumLegend(CompanyKind::class) . '.',
                    'enum'        => array_column(CompanyKind::cases(), 'value'),
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

        $kind = null;

        if ($given = $args->string('kind')) {
            $kind = Vocabulary::enumValue(CompanyKind::class, $given);

            if ($kind === null) {
                return ToolResult::failure(
                    "\"{$given}\" não é um tipo de empresa. Use um destes: "
                    . Vocabulary::enumLegend(CompanyKind::class) . '.',
                );
            }
        }

        $query = Company::query()->filter([
            'search' => $args->string('query'),
            'kind'   => $kind,
        ]);

        $total = $query->count();
        $limit = $args->int('limit', 25, 1, 100);
        $companies = $query->orderBy('name')->limit($limit)->get();

        return ToolResult::json([
            'total'     => $total,
            'returned'  => $companies->count(),
            'truncated' => $total > $companies->count(),
            'companies' => $companies->map(fn (Company $c) => $this->presenter->companySummary($c))->all(),
        ]);
    }
}
