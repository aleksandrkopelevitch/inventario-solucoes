<?php

namespace App\Mcp\Tools;

use App\Enums\AccessModule;
use App\Mcp\Actor;
use App\Mcp\Support\Arguments;
use App\Mcp\Support\Presenter;
use App\Mcp\Support\ReadableNotebooks;
use App\Mcp\Tool;
use App\Mcp\ToolResult;
use App\Models\Solution;
use App\Services\SolutionRelationshipService;

/**
 * One solution's whole record.
 *
 * Every relation is eager-loaded here rather than left to the presenter, for the
 * reason § Strict mode spells out: this is a SINGLE-ROW fetch, so
 * `Model::shouldBeStrict()` never arms and a missing `with()` lazy-loads in
 * silence — no exception, in any environment. `people` and `notebooks` are
 * collections walked one row at a time by the presenter, which is exactly the
 * N+1 that would never be caught by a test.
 */
class GetSolution implements Tool
{
    public function __construct(
        private readonly Presenter $presenter,
        private readonly SolutionRelationshipService $relationships,
    ) {}

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
        cada uma), cadernos publicados que a documentam e os relacionamentos com outras
        soluções.

        "relationships" vem dos diagramas UML da documentação: cada item diz com qual
        solução ela se comunica, em que sentido ("chama", "é chamada por", "mútua",
        "sem sentido definido"), por qual plataforma de integração passa quando não é
        direto ("via") e em que página está documentado ("evidence").

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

        $solution = Solution::query()->where('slug', $slug)->first();

        if (! $solution) {
            return ToolResult::failure(
                "Nenhuma solução com o slug \"{$slug}\". Use search_solutions para encontrar o slug correto.",
            );
        }

        return ToolResult::json($this->present($solution, $actor));
    }

    /**
     * The record as this tool answers it — also what every write tool on a
     * solution answers with, so a model that created one reads the same shape
     * it would get by opening it.
     *
     * @return array<string, mixed>
     */
    public function present(Solution $solution, Actor $actor): array
    {
        $solution->load([
            'vendor',
            'people:id,slug,name',
            'notebooks',
        ]);

        return $this->presenter->solution(
            $solution,
            ReadableNotebooks::seesUnpublished($actor),
            $this->relationships->forSolution($solution),
            // Where a relationship is documented is the documentation module's:
            // a caller at level None there gets the pairs without the pages.
            withEvidence: $actor->canRead(AccessModule::Documentation),
        );
    }
}
