<?php

namespace App\Mcp\Tools;

use App\Mcp\Support\Presenter;
use App\Mcp\Support\PublishedNotebooks;
use App\Mcp\Tool;
use App\Mcp\ToolResult;
use App\Models\Notebook;

/**
 * Every caderno this token can read — the discovery step the other three
 * documentation tools take a slug from.
 *
 * The empty case carries a message rather than an empty list, and it is not a
 * nicety: until an admin publishes something this tool legitimately returns
 * nothing, and a model handed `[]` reports "não há documentação", which is false
 * about an app holding six hundred pages. What is true is that none of it has
 * been published, and that sentence points at who can change it.
 */
class ListNotebooks implements Tool
{
    public function __construct(
        private readonly PublishedNotebooks $notebooks,
        private readonly Presenter $presenter,
    ) {}

    public function name(): string
    {
        return 'list_notebooks';
    }

    public function title(): string
    {
        return 'Listar cadernos publicados';
    }

    public function description(): string
    {
        return <<<'TXT'
        Lista os cadernos de documentação publicados na base de conhecimento interna, com
        as soluções que cada um documenta e quantas páginas tem.

        É o ponto de partida da documentação: use o slug retornado em get_notebook (para
        a árvore de páginas) ou em search_documentation (para buscar dentro dele).
        TXT;
    }

    public function inputSchema(): array
    {
        return ['type' => 'object', 'properties' => (object) [], 'required' => []];
    }

    public function handle(array $arguments): ToolResult
    {
        // `documentation` non-blank, the same definition of "tem conteúdo" as
        // `Notebook::documentedPages()` — a page with an empty body is a
        // heading with nothing under it, and counting it makes a caderno look
        // fuller than it reads.
        $notebooks = $this->notebooks->all()->loadCount([
            'pages as documented_pages' => fn ($q) => $q
                ->whereNotNull('documentation')->where('documentation', '<>', ''),
        ]);

        if ($notebooks->isEmpty()) {
            return ToolResult::json([
                'notebooks' => [],
                'note'      => 'Nenhum caderno foi publicado na base de conhecimento ainda. '
                    . 'Existe documentação no app, mas ela só fica visível aqui depois que um '
                    . 'administrador publica o caderno em /docs.',
            ]);
        }

        return ToolResult::json([
            'total'     => $notebooks->count(),
            'notebooks' => $notebooks
                ->map(fn (Notebook $notebook) => [
                    ...$this->presenter->notebookSummary($notebook),
                    'documented_pages' => $notebook->documented_pages,
                ])
                ->all(),
        ]);
    }
}
