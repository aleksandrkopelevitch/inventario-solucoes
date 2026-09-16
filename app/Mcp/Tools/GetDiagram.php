<?php

namespace App\Mcp\Tools;

use App\Mcp\Support\Arguments;
use App\Mcp\Support\Presenter;
use App\Mcp\Tool;
use App\Mcp\ToolResult;
use App\Models\Diagram;

/**
 * One drawing, as a graph.
 *
 * This is the tool a `{% diagram slug="…" %}` citation inside a documentation
 * page resolves to — which is why `get_documentation_page` leaves those blocks
 * in the Markdown it returns instead of stripping them. A page that says "o
 * fluxo está no diagrama X" is only half an answer until the model can open X,
 * and the slug in the citation is the argument this tool takes.
 */
class GetDiagram implements Tool
{
    public function __construct(private readonly Presenter $presenter) {}

    public function requiresInventory(): bool
    {
        // Catálogo: fechado para quem não lê o inventário no app.
        return true;
    }

    public function name(): string
    {
        return 'get_diagram';
    }

    public function title(): string
    {
        return 'Abrir um diagrama';
    }

    public function description(): string
    {
        return <<<'TXT'
        Topologia completa de um diagrama pelo slug: os blocos (sistemas, decisões,
        atores, início/fim), as ligações entre eles com direção e protocolo, as soluções
        que participam do desenho e uma leitura em texto do fluxo ("A -> B -> C").

        Em "edges", "from" e "to" são ÍNDICES na lista "nodes" (0 é o primeiro bloco).

        É também como se resolve uma citação {% diagram slug="..." %} encontrada no texto
        de uma página de documentação: passe aqui o slug citado.
        TXT;
    }

    public function inputSchema(): array
    {
        return [
            'type'       => 'object',
            'properties' => [
                'slug' => [
                    'type'        => 'string',
                    'description' => 'Slug do diagrama, vindo de search_diagrams ou de uma citação {% diagram slug="..." %}.',
                ],
            ],
            'required' => ['slug'],
        ];
    }

    public function handle(array $arguments): ToolResult
    {
        $slug = (new Arguments($arguments))->required('slug');

        // `participants` is ordered by its pivot `position`, which is the order
        // the flow reads in — worth loading through the relation rather than
        // re-querying the solutions out of the chain's node list.
        $diagram = Diagram::query()
            ->with('participants')
            ->where('slug', $slug)
            ->first();

        if (! $diagram) {
            return ToolResult::failure(
                "Nenhum diagrama com o slug \"{$slug}\". Use search_diagrams para encontrar o slug correto.",
            );
        }

        return ToolResult::json($this->presenter->diagram($diagram));
    }
}
