<?php

namespace App\Mcp\Tools;

use App\Mcp\Support\Arguments;
use App\Mcp\Support\Presenter;
use App\Mcp\Tool;
use App\Mcp\ToolResult;
use App\Models\Company;

/** One company: its contacts and everything it supplies to Leo. */
class GetCompany implements Tool
{
    public function __construct(private readonly Presenter $presenter) {}

    public function name(): string
    {
        return 'get_company';
    }

    public function title(): string
    {
        return 'Abrir uma empresa';
    }

    public function description(): string
    {
        return <<<'TXT'
        Cadastro completo de uma empresa pelo slug: tipo, site, observações, pessoas de
        contato e as soluções que ela fornece.

        Obtenha o slug com search_companies, ou pelo campo "vendor" de uma solução.
        TXT;
    }

    public function inputSchema(): array
    {
        return [
            'type'       => 'object',
            'properties' => [
                'slug' => ['type' => 'string', 'description' => 'Slug da empresa.'],
            ],
            'required' => ['slug'],
        ];
    }

    public function handle(array $arguments): ToolResult
    {
        $slug = (new Arguments($arguments))->required('slug');

        $company = Company::query()
            ->with(['people:id,slug,name,job_title,company_id', 'providedSolutions'])
            ->where('slug', $slug)
            ->first();

        if (! $company) {
            return ToolResult::failure(
                "Nenhuma empresa com o slug \"{$slug}\". Use search_companies para encontrar o slug correto.",
            );
        }

        return ToolResult::json($this->presenter->company($company));
    }
}
