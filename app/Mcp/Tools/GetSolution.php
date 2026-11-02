<?php

namespace App\Mcp\Tools;

use App\Enums\AccessModule;
use App\Mcp\Actor;
use App\Mcp\Support\Arguments;
use App\Mcp\Support\Presenter;
use App\Mcp\Tool;
use App\Mcp\ToolResult;
use App\Models\Solution;

/**
 * One solution's whole record.
 *
 * Every relation is eager-loaded here rather than left to the presenter, for the
 * reason § Strict mode spells out: this is a SINGLE-ROW fetch, so
 * `Model::shouldBeStrict()` never arms and a missing `with()` lazy-loads in
 * silence — no exception, in any environment. `people` and `diagrams` are
 * collections walked one row at a time by the presenter, which is exactly the
 * N+1 that would never be caught by a test.
 */
class GetSolution implements Tool
{
    public function __construct(private readonly Presenter $presenter) {}

    public function module(): ?AccessModule
    {
        return AccessModule::Catalog;
    }

    public function name(): string
    {
        return 'get_solution';
    }

    public function title(): string
    {
        return 'Abrir uma solução';
    }

    public function description(): string
    {
        return <<<'TXT'
        Cadastro completo de uma solução pelo slug: descrição, categoria, criticidade,
        ambiente, contrato, empresa fornecedora, pessoas responsáveis (com o papel de
        cada uma), diagramas de que ela participa e cadernos publicados que a documentam.

        Obtenha o slug com search_solutions — não o invente. A lista de cadernos cobre
        apenas os publicados na base de conhecimento: uma solução pode estar documentada
        em um caderno ainda não publicado e aparecer aqui sem nenhum.
        TXT;
    }

    public function inputSchema(): array
    {
        return [
            'type'       => 'object',
            'properties' => [
                'slug' => [
                    'type'        => 'string',
                    'description' => 'Slug da solução, como retornado por search_solutions (ex.: "sap-ecc").',
                ],
            ],
            'required' => ['slug'],
        ];
    }

    public function handle(array $arguments, Actor $actor): ToolResult
    {
        $slug = (new Arguments($arguments))->required('slug');

        // The diagrams are the documentation module's: a caller at level None
        // there gets the solution without them, as the solution page in the
        // browser hides that card behind `viewAny` on Notebook.
        $withDiagrams = $actor->canRead(AccessModule::Documentation);

        $solution = Solution::query()
            ->with([
                'vendor',
                'people:id,slug,name',
                'notebooks',
                ...($withDiagrams ? [
                    'diagrams:id,notebook_id,slug,name,status',
                    'diagrams.notebook:id,name,slug,published_at',
                ] : []),
            ])
            ->where('slug', $slug)
            ->first();

        if (! $solution) {
            return ToolResult::failure(
                "Nenhuma solução com o slug \"{$slug}\". Use search_solutions para encontrar o slug correto.",
            );
        }

        return ToolResult::json($this->presenter->solution($solution, $withDiagrams));
    }
}
