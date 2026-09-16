<?php

namespace App\Mcp\Tools;

use App\Mcp\Support\Arguments;
use App\Mcp\Support\Presenter;
use App\Mcp\Support\PublishedNotebooks;
use App\Mcp\Tool;
use App\Mcp\ToolResult;
use App\Support\Documentation\SecretText;

/**
 * One documentation page, as the Markdown it was written in.
 *
 * **Markdown rather than rendered HTML**, and rather than plain text, for three
 * reasons that all point the same way: it is what the author wrote, it costs the
 * fewest tokens of the three, and it keeps the constructs of the dialect legible
 * — a `{% hint %}` still reads as a warning, and a `{% diagram slug="…" %}` is
 * still a slug `get_diagram` can be called with. Stripping them would leave
 * prose referring to a picture the model has no way to ask about.
 *
 * **`SecretText::mask()` is the load-bearing line in this file.** This is the
 * seventh surface that hands a page's text to somebody (after the editor, the
 * two read-only copies, "Copiar Markdown", the documentation assistant's prompt
 * and the flowSpec one), and it is by far the most exposed: the text leaves the
 * building. A protected value exists so that an `Authorization` header can sit
 * in documentation without being readable by every editor who opens the page —
 * a bearer token in somebody's chat client is not the audience that rule was
 * relaxed for. What the model sees is `{% secret %}[[SECRET-1]]{% endsecret %}`:
 * enough to say "há um valor protegido aqui", nothing to quote.
 *
 * There is deliberately no reveal tool beside this one. The plaintext has
 * exactly one door (`RevealPageSecret`), it is throttled per reader, and a
 * second door reached by a static token would be the whole protection undone.
 */
class GetDocumentationPage implements Tool
{
    public function __construct(
        private readonly PublishedNotebooks $notebooks,
        private readonly Presenter $presenter,
    ) {}

    public function requiresInventory(): bool
    {
        // Documentação publicada: o que /docs entrega a qualquer conta Leo.
        return false;
    }

    public function name(): string
    {
        return 'get_documentation_page';
    }

    public function title(): string
    {
        return 'Ler uma página de documentação';
    }

    public function description(): string
    {
        return <<<'TXT'
        Texto completo de uma página de documentação, em Markdown, de um caderno
        publicado. Exige o slug do caderno e o slug da página (de get_notebook ou de
        search_documentation).

        O texto pode conter construções do dialeto GitBook: {% hint %} (destaque),
        {% tabs %}, {% file %} (anexo) e {% diagram slug="..." %} — esta última cita um
        diagrama, que se abre com get_diagram usando o slug citado. Links internos têm a
        forma [texto](page:slug-da-pagina) e apontam para outra página do mesmo caderno.

        Valores protegidos aparecem como {% secret %}[[SECRET-n]]{% endsecret %}: existe
        um segredo ali (senha, token, header de autenticação) e ele não é entregue por
        esta interface. Diga que o valor é protegido e está na página; nunca o invente.
        TXT;
    }

    public function inputSchema(): array
    {
        return [
            'type'       => 'object',
            'properties' => [
                'notebook' => [
                    'type'        => 'string',
                    'description' => 'Slug do caderno.',
                ],
                'page' => [
                    'type'        => 'string',
                    'description' => 'Slug da página dentro desse caderno.',
                ],
            ],
            'required' => ['notebook', 'page'],
        ];
    }

    public function handle(array $arguments): ToolResult
    {
        $args = new Arguments($arguments);
        $notebookSlug = $args->required('notebook');
        $pageSlug = $args->required('page');

        $notebook = $this->notebooks->find($notebookSlug);

        if (! $notebook) {
            return ToolResult::failure($this->notebooks->notFound($notebookSlug));
        }

        // Through the notebook's own relation, which is what the app's
        // `scopeBindings()` route does (§ Routing): a page slug is unique per
        // caderno and not across them, so a global lookup would serve a page of
        // a caderno this token may not read, addressed through one it may.
        $page = $notebook->pages()->where('slug', $pageSlug)->first();

        if (! $page) {
            return ToolResult::failure(
                "O caderno \"{$notebook->name}\" não tem nenhuma página com o slug \"{$pageSlug}\". "
                . 'Use get_notebook para ver a árvore de páginas.',
            );
        }

        $body = SecretText::mask($page->documentation);

        if (blank($body)) {
            return ToolResult::json([
                ...$this->presenter->pageSummary($page, $notebook),
                'note' => 'Esta página não tem conteúdo — é só um título que agrupa subpáginas.',
            ]);
        }

        return ToolResult::document(
            $this->presenter->pageSummary($page, $notebook),
            $body,
        );
    }
}
