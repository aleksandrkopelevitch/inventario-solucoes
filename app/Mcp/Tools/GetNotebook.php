<?php

namespace App\Mcp\Tools;

use App\Mcp\Support\Arguments;
use App\Mcp\Support\Presenter;
use App\Mcp\Support\PublishedNotebooks;
use App\Mcp\Tool;
use App\Mcp\ToolResult;
use App\Models\DocumentationPage;
use App\Services\DocumentationPageService;

/**
 * A caderno's page tree, in READING order.
 *
 * `DocumentationPageService::tree()` and never `$notebook->pages()`, which is
 * the trap § Cadernos names: `position` orders a page among its SIBLINGS, so a
 * flat `orderBy('position')` interleaves depths and is not reading order —
 * a model walking it would present a subpage as if it followed its uncle.
 * `tree()` is one query with the recursion in memory and carries each row's
 * `depth`, which is what makes the shape legible in a flat JSON list.
 */
class GetNotebook implements Tool
{
    public function __construct(
        private readonly PublishedNotebooks $notebooks,
        private readonly DocumentationPageService $pages,
        private readonly Presenter $presenter,
    ) {}

    public function requiresInventory(): bool
    {
        // Documentação publicada: o que /docs entrega a qualquer conta Leo.
        return false;
    }

    public function name(): string
    {
        return 'get_notebook';
    }

    public function title(): string
    {
        return 'Abrir um caderno';
    }

    public function description(): string
    {
        return <<<'TXT'
        Árvore de páginas de um caderno publicado, em ordem de leitura, com o nível de
        cada página ("depth": 0 é uma página raiz). Não traz o texto das páginas — use
        get_documentation_page com o slug da página para ler o conteúdo.

        Para procurar um assunto sem saber em que página ele está, prefira
        search_documentation.
        TXT;
    }

    public function inputSchema(): array
    {
        return [
            'type'       => 'object',
            'properties' => [
                'notebook' => [
                    'type'        => 'string',
                    'description' => 'Slug do caderno, como retornado por list_notebooks.',
                ],
            ],
            'required' => ['notebook'],
        ];
    }

    public function handle(array $arguments): ToolResult
    {
        $slug = (new Arguments($arguments))->required('notebook');
        $notebook = $this->notebooks->find($slug);

        if (! $notebook) {
            return ToolResult::failure($this->notebooks->notFound($slug));
        }

        $tree = $this->pages->tree($notebook);

        return ToolResult::json([
            ...$this->presenter->notebookSummary($notebook),
            'pages' => $tree
                ->map(function (array $row) use ($notebook) {
                    /** @var DocumentationPage $page */
                    $page = $row['page'];

                    return [
                        ...$this->presenter->pageSummary($page, $notebook, $row['depth']),
                        // Whether there is anything to read, so a model does not
                        // spend a call on a section heading that exists only to
                        // hold its children. 49 of the imported Digibee levels
                        // are exactly that.
                        'has_content' => filled($page->documentation),
                    ];
                })
                ->all(),
        ]);
    }
}
