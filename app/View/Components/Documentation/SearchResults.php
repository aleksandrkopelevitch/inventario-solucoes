<?php

namespace App\View\Components\Documentation;

use App\View\Components\Concerns\Renderable;
use Illuminate\Contracts\View\View;
use Illuminate\View\Component;

/**
 * The body of the public documentation command palette — facet chips, the
 * result count and the ranked hits — rendered as ONE updatable slot swapped on
 * every keystroke by `docs-search.js`.
 *
 * Server-rendered on purpose, rather than built from JSON in the browser: a
 * hit's title and snippet are the visitor-facing text of a page somebody
 * authored, and Blade escapes it for free. The payload the client receives
 * carries highlight RANGES (`{text, match}` segments), never markup, so there
 * is no path from page content to innerHTML anywhere in this feature.
 *
 * The search input itself lives OUTSIDE this slot (see the palette view) —
 * swapping it on every keystroke would drop the caret.
 */
class SearchResults extends Component
{
    use Renderable;

    public const DOM_ID = 'docs-search-results-slot';

    /** Chip labels for the content facets — plural, they head a count. */
    public const TAG_LABELS = [
        'table'   => 'Tabelas',
        'code'    => 'Código',
        'image'   => 'Imagens',
        'callout' => 'Avisos',
        'file'    => 'Arquivos',
        'diagram' => 'Diagramas',
    ];

    /** The same facets as a badge ON a result — singular, they describe one hit. */
    public const TAG_BADGES = [
        'table'   => 'tabela',
        'code'    => 'código',
        'image'   => 'imagem',
        'callout' => 'aviso',
        'file'    => 'arquivo',
        'diagram' => 'diagrama',
    ];

    /** @param  array<string, mixed>  $payload  Whatever DocumentationSearchService::search() returned. */
    public function __construct(public array $payload) {}

    /**
     * @param  array<string, mixed>  $payload
     * @return array{id: string, content: string}
     */
    public static function slot(array $payload): array
    {
        return (new static($payload))->toSlot(self::DOM_ID);
    }

    public function render(): View
    {
        $payload = $this->payload;
        $filters = $payload['filters'] ?? ['section' => null, 'tag' => null];
        $narrowed = trim((string) ($payload['query'] ?? '')) !== ''
            || $filters['section'] !== null
            || $filters['tag'] !== null;

        return view('components.documentation.search-results', [
            'domId'     => self::DOM_ID,
            'results'   => $payload['results'] ?? [],
            'facets'    => $payload['facets'] ?? ['sections' => [], 'tags' => []],
            'filters'   => $filters,
            'total'     => (int) ($payload['total'] ?? 0),
            'shown'     => (int) ($payload['shown'] ?? 0),
            'overview'  => $payload['overview'] ?? ['pages' => 0, 'sections' => 0, 'words' => 0],
            'narrowed'  => $narrowed,
            'tagLabels' => self::TAG_LABELS,
            'tagBadges' => self::TAG_BADGES,
        ]);
    }
}
