<?php

namespace App\Mcp\Tools\Write;

use App\Actions\Documentation\SavePageContent;
use App\Mcp\Actor;
use App\Mcp\Support\Arguments;
use App\Mcp\ToolResult;
use App\Models\DocumentationPage;
use App\Support\Fold;
use App\Support\GitbookRenderer;
use Illuminate\Support\Facades\Validator;

/**
 * A new page in a caderno — at the root or under another page ("seções" are
 * pages with subpages), optionally at a given position and with its Markdown.
 * The tree rules are `DocumentationPageService`'s and the body goes through
 * `SavePageContent`, the same doors the app's rail and editor use.
 */
class CreatePage extends DocumentationWriteTool implements CreatesRecords
{
    public function name(): string
    {
        return 'create_page';
    }

    public function title(): string
    {
        return 'Criar uma página';
    }

    public function description(): string
    {
        return <<<'TXT'
        Cria uma página num caderno, em nome da pessoa conectada: na raiz ou dentro de
        outra página ("parent"), opcionalmente numa posição e já com o texto em Markdown,
        no dialeto do app (veja get_documentation_page). Uma página com o mesmo título no
        mesmo lugar é recusada. Até 5 níveis. Confirme com a pessoa.

        O texto pode trazer diagramas UML ({% uml %}) e cenas animadas ({% scene %}):
        a sintaxe está em get_code_documentation_guide. Um diagrama ou uma cena com erro
        recusa a chamada, listando cada problema — corrija e repita.
        TXT;
    }

    public function inputSchema(): array
    {
        return [
            'type'       => 'object',
            'properties' => [
                'notebook'        => ['type' => 'string', 'description' => 'Slug do caderno.'],
                'title'           => ['type' => 'string', 'description' => 'Título da página.'],
                'parent'          => ['type' => 'string', 'description' => 'Slug da página onde ela entra. Omita para a raiz.'],
                'position'        => ['type' => 'integer', 'description' => 'Posição entre as irmãs, começando em 0. Omita para o fim.'],
                'content'         => ['type' => 'string', 'description' => 'Texto da página em Markdown.'],
                'allow_duplicate' => ['type' => 'boolean', 'description' => 'Só com confirmação da pessoa: cria mesmo com outra página de mesmo título no mesmo lugar.'],
            ],
            'required' => ['notebook', 'title'],
        ];
    }

    /** Locks on the caderno and the TITLE, which is a page's name. */
    protected function lockKey(array $arguments): ?string
    {
        $title = $arguments['title'] ?? null;

        return is_scalar($title) && trim((string) $title) !== ''
            ? static::class . ':' . md5(($arguments['notebook'] ?? '') . '|' . Fold::text((string) $title))
            : null;
    }

    protected function write(array $arguments, Actor $actor): ToolResult
    {
        $args = new Arguments($arguments);
        $notebook = $this->notebook($args->required('notebook'), $actor);

        if ($notebook instanceof ToolResult) {
            return $notebook;
        }

        if (! $actor->user?->can('update', $notebook)) {
            return $this->forbidden('criar páginas neste caderno');
        }

        $title = $args->string('title') ?? '';
        $content = is_string($arguments['content'] ?? null) ? $arguments['content'] : null;

        $validator = Validator::make(
            ['title' => $title, 'content' => $content],
            ['title' => ['required', 'string', 'max:255'], 'content' => ['nullable', 'string', 'max:500000']],
        );

        if ($validator->fails()) {
            return $this->refused($validator->errors()->all());
        }

        if ($content !== null && ($problems = app(GitbookRenderer::class)->problems($content)) !== []) {
            return $this->refused([...$problems, 'A sintaxe de {% uml %} e {% scene %} está em get_code_documentation_guide.']);
        }

        $parent = null;

        if ($parentSlug = $args->string('parent')) {
            $parent = $this->page($notebook, $parentSlug);

            if ($parent instanceof ToolResult) {
                return $parent;
            }

            if (! $parent->canReceiveChildren()) {
                return $this->refused(['parent: esta página já está no último nível — ela não pode receber subpáginas.']);
            }
        }

        $position = is_numeric($arguments['position'] ?? null) ? (int) $arguments['position'] : null;

        return $this->createOnce(
            $actor,
            DocumentationPage::class,
            $title,
            array_filter(['title' => $title, 'documentation' => $content], fn ($value) => $value !== null),
            ['title' => 'title', 'documentation' => 'content'],
            (bool) ($arguments['allow_duplicate'] ?? false),
            function () use ($notebook, $title, $parent, $position, $content) {
                $page = $this->pages->create($notebook, $title, $parent);

                if ($position !== null) {
                    $this->pages->place($page, $parent, $position);
                }

                if ($content !== null) {
                    app(SavePageContent::class)->handle($page, $content);
                }

                return $page;
            },
            fn (DocumentationPage $page, ?string $note) => $this->pageAnswer($page, $notebook, $note ?? 'Página criada.'),
            // "The same page" is the same title in the same place: two
            // "Visão geral" under two different sections are two pages.
            scope: fn ($query) => $query->where('notebook_id', $notebook->getKey())->where('parent_id', $parent?->getKey()),
            nameColumn: 'title',
        );
    }
}
