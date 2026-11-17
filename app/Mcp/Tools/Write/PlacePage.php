<?php

namespace App\Mcp\Tools\Write;

use App\Mcp\Actor;
use App\Mcp\Support\Arguments;
use App\Mcp\Support\RecordFields;
use App\Mcp\ToolResult;

/**
 * Puts a page at an absolute spot in its caderno's tree — under `parent` (or
 * at the root), at `position` among its siblings. One call for any
 * rearrangement: the app's rail moves a page one step at a time, and a model
 * rearranging a tree with steps would make a dozen calls and miscount one.
 * The page takes its whole subtree along.
 */
class PlacePage extends DocumentationWriteTool
{
    public function name(): string
    {
        return 'place_page';
    }

    public function title(): string
    {
        return 'Mover uma página no caderno';
    }

    public function idempotent(): bool
    {
        return true;
    }

    public function destructive(): bool
    {
        return true;
    }

    public function description(): string
    {
        return <<<'TXT'
        Move uma página (com as subpáginas dela) para outro lugar do mesmo caderno: dentro
        da página "parent" — ou na raiz, se "parent" for omitido — na posição "position"
        entre as irmãs (0 = primeira; omita para o fim). Até 5 níveis. Confirme com a pessoa.
        TXT;
    }

    public function inputSchema(): array
    {
        return [
            'type'       => 'object',
            'properties' => [
                'notebook' => ['type' => 'string', 'description' => 'Slug do caderno.'],
                'page'     => ['type' => 'string', 'description' => 'Slug da página a mover.'],
                'parent'   => ['type' => 'string', 'description' => 'Slug da página onde ela entra. Omita para a raiz.'],
                'position' => ['type' => 'integer', 'description' => 'Posição entre as irmãs, começando em 0. Omita para o fim.'],
            ],
            'required' => ['notebook', 'page'],
        ];
    }

    protected function write(array $arguments, Actor $actor): ToolResult
    {
        $args = new Arguments($arguments);
        $notebook = $this->notebook($args->required('notebook'), $actor);

        if ($notebook instanceof ToolResult) {
            return $notebook;
        }

        $page = $this->page($notebook, $args->required('page'));

        if ($page instanceof ToolResult) {
            return $page;
        }

        if (! $actor->user?->can('update', $page)) {
            return $this->forbidden('reorganizar as páginas deste caderno');
        }

        $parent = null;

        if ($parentSlug = $args->string('parent')) {
            $parent = $this->page($notebook, $parentSlug);

            if ($parent instanceof ToolResult) {
                return $parent;
            }
        }

        if (($failure = $this->pages->placementFailure($page, $parent)) !== null) {
            return $this->refused([$failure]);
        }

        $before = RecordFields::snapshot($page, ['placement']);
        $this->pages->place($page, $parent, $args->int('position', PHP_INT_MAX, 0, PHP_INT_MAX));
        $changed = $this->log($actor, $page, $before, RecordFields::snapshot($page->refresh(), ['placement']));

        return $this->pageAnswer($page, $notebook, $changed ? 'Página movida.' : 'Nada mudou: a página já estava nesse lugar.');
    }
}
