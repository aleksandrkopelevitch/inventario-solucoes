<?php

namespace App\Mcp\Tools;

use App\Mcp\Support\Arguments;
use App\Mcp\Support\PublishedNotebooks;
use App\Mcp\Tool;
use App\Mcp\ToolResult;
use App\Models\Notebook;
use App\Services\DocumentationSearchService;

/**
 * Full-text search over the published documentation.
 *
 * It is `DocumentationSearchService`, the same index the ⌘K palette runs on, so
 * three of its properties come for free and all three matter here: the unit of a
 * result is the SECTION rather than the page (so a hit points at the heading it
 * is under, not at a 3000-word page), anchors are read back out of the rendered
 * HTML rather than re-derived, and the index is cached by CONTENT — the ~6 s
 * cold build is paid once per page, not per search.
 *
 * It also inherits the one property that makes this tool safe to expose: the
 * index is built from the RENDERED html, which contains LOCKS where a
 * `{% secret %}` was. There is no path from this search to a protected value,
 * and there never was one to close.
 *
 * **Searching every caderno is bounded by TIME, not by a count.** A cold corpus
 * costs seconds to index, so "todos os cadernos publicados" is a request whose
 * cost nobody can state in advance — and the failure it produces is the worst
 * kind, a request that hangs until the gateway gives up and a model that learns
 * nothing about why. Instead the walk stops when it has spent its budget and
 * REPORTS which cadernos it did not reach, so a partial answer says it is
 * partial. The next call is fast: every caderno it did reach is now warm.
 */
class SearchDocumentation implements Tool
{
    /**
     * Seconds to spend indexing and searching before answering with what is in
     * hand. Well under any sane HTTP timeout, and enough for several warm
     * cadernos or one large cold one.
     */
    private const BUDGET_SECONDS = 20.0;

    public function __construct(
        private readonly PublishedNotebooks $notebooks,
        private readonly DocumentationSearchService $search,
    ) {}

    public function requiresInventory(): bool
    {
        // Documentação publicada: o que /docs entrega a qualquer conta Leo.
        return false;
    }

    public function name(): string
    {
        return 'search_documentation';
    }

    public function title(): string
    {
        return 'Buscar na documentação';
    }

    public function description(): string
    {
        return <<<'TXT'
        Busca texto na documentação publicada. Cada resultado é uma SEÇÃO (um título H1–H3
        e o texto sob ele), não a página inteira, e traz o caderno, a página, o caminho até
        ela e um trecho com o contexto do que casou.

        Sem "notebook", busca em todos os cadernos publicados; com ele, só naquele. Os
        resultados são ordenados por relevância DENTRO de cada caderno, não entre cadernos.

        "scopes" diz ONDE procurar: prose (texto corrido), table (tabelas) ou code (blocos
        de código). Um nome de coluna vive em tabelas, uma variável de ambiente em código,
        uma política em texto — buscar nos três ao mesmo tempo enterra o que se queria.
        Omitir busca nos três.

        Use get_documentation_page com o slug do caderno e o da página para ler o texto
        completo de um resultado.
        TXT;
    }

    public function inputSchema(): array
    {
        return [
            'type'       => 'object',
            'properties' => [
                'query' => [
                    'type'        => 'string',
                    'description' => 'Termos a procurar. A busca ignora acentos e maiúsculas.',
                ],
                'notebook' => [
                    'type'        => 'string',
                    'description' => 'Slug de um caderno, para restringir a busca a ele. Omita para buscar em todos os publicados.',
                ],
                'scopes' => [
                    'type'        => 'array',
                    'description' => 'Onde procurar. Omita para buscar em tudo.',
                    'items'       => ['type' => 'string', 'enum' => DocumentationSearchService::SCOPES],
                ],
                'limit' => [
                    'type'        => 'integer',
                    'description' => 'Máximo de resultados (1–50, padrão 20).',
                    'default'     => 20,
                ],
            ],
            'required' => ['query'],
        ];
    }

    public function handle(array $arguments): ToolResult
    {
        $args = new Arguments($arguments);
        $query = $args->required('query');
        $limit = $args->int('limit', 20, 1, 50);
        $scopes = array_values(array_intersect($args->list('scopes'), DocumentationSearchService::SCOPES));

        if ($slug = $args->string('notebook')) {
            $notebook = $this->notebooks->find($slug);

            if (! $notebook) {
                return ToolResult::failure($this->notebooks->notFound($slug));
            }

            $corpora = collect([$notebook]);
        } else {
            $corpora = $this->notebooks->all();
        }

        if ($corpora->isEmpty()) {
            return ToolResult::json([
                'results' => [],
                'note'    => 'Nenhum caderno foi publicado na base de conhecimento ainda, '
                    . 'então não há o que buscar por aqui.',
            ]);
        }

        $deadline = microtime(true) + self::BUDGET_SECONDS;
        $results = [];
        $searched = [];
        $skipped = [];
        $total = 0;

        foreach ($corpora as $notebook) {
            // Never on the FIRST caderno: a budget that can answer nothing at
            // all turns a slow search into a silent empty one, which reads
            // exactly like a corpus that does not mention the term.
            if ($searched !== [] && microtime(true) > $deadline) {
                $skipped[] = $notebook->slug;

                continue;
            }

            $payload = $this->search->search($notebook, $query, $scopes === [] ? [] : ['scopes' => $scopes]);
            $searched[] = $notebook->slug;
            $total += $payload['total'];

            foreach ($payload['results'] as $result) {
                $results[] = $this->shape($result, $notebook);
            }
        }

        return ToolResult::json(array_filter([
            'query'              => $query,
            'total'              => $total,
            'returned'           => min(count($results), $limit),
            'truncated'          => count($results) > $limit,
            'searched_notebooks' => $searched,
            // Only present when the budget actually ran out, so its presence IS
            // the statement that the answer is partial.
            'not_searched_notebooks' => $skipped ?: null,
            'results'                => array_slice($results, 0, $limit),
        ], fn ($value) => $value !== null));
    }

    /**
     * One palette result, flattened for a model.
     *
     * `title` and `snippet` arrive as highlight SEGMENTS (`{text, match}`) —
     * ranges rather than markup, which is what keeps the app's own rendering
     * free of any path from authored content to `innerHTML` (§ Public
     * documentation search). A model wants the sentence, so the segments are
     * concatenated back into one; the ranges carried no information a model
     * could use, having been about where to paint a `<mark>`.
     *
     * @param  array<string, mixed>  $result
     * @return array<string, mixed>
     */
    private function shape(array $result, Notebook $notebook): array
    {
        return array_filter([
            'notebook' => $notebook->slug,
            'page'     => $result['page'],
            'section'  => $this->flatten($result['title']),
            // The heading's own anchor, read out of the rendered HTML by the
            // index — accents and `-1` collision suffixes included. Appended to
            // the page URL it lands the reader on the section, not the page top.
            'anchor'  => $result['anchor'],
            'trail'   => $result['trail'] ?: null,
            'snippet' => $this->flatten($result['snippet']),
            'url'     => route('docs.page', [$notebook->slug, $result['slug']])
                . ($result['anchor'] ? '#' . rawurlencode((string) $result['anchor']) : ''),
            'page_slug' => $result['slug'],
        ], fn ($value) => $value !== null && $value !== '');
    }

    /** @param list<array{text: string, match: bool}> $segments */
    private function flatten(array $segments): string
    {
        return implode('', array_column($segments, 'text'));
    }
}
