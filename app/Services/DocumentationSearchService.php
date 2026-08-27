<?php

namespace App\Services;

use App\Models\DocumentationPage;
use App\Models\Notebook;
use App\Support\GitbookRenderer;
use DOMDocument;
use DOMElement;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Cache;

/**
 * Turns a `Notebook` into a searchable, faceted DATASET — the corpus behind the
 * search panel on the public "magic link" documentation.
 *
 * ## Sub-page granularity, with no overlap
 *
 * The unit of a result is not the page: it is the SECTION. Every H1–H3 in a
 * page's rendered HTML opens a new entry, carrying that heading's own body and
 * nothing else, so a hit lands on the anchor inside a long page rather than on
 * the page's top. The page itself is still an entry, but its text is only what
 * sits BEFORE the first heading (the "lead"). Every character of the corpus
 * therefore belongs to exactly one entry — a search for a word never returns
 * the same passage twice, once as a page and once as a section.
 *
 * ## Anchors come from the rendered HTML, never re-derived
 *
 * `GitbookRenderer` emits `<a class="heading-permalink" id="{slug}">` after
 * every H1–H3 (see `docs-anchors.js`), and the ids in the index are read back
 * out of that HTML. Re-implementing commonmark's slug normalizer here would
 * drift the moment two headings on a page collide and it starts suffixing
 * `-1`, and a drifted anchor is a silent failure: the link opens the right
 * page at the wrong place.
 *
 * ## Two cache layers, both keyed by content
 *
 * Rendering the corpus is the expensive half (measured 2026-08-26: ~4.8s for
 * 132 pages / 2 MB), so it never happens on a visitor's request twice:
 *
 * - **per page**, keyed by a hash of that page's own content — editing one
 *   page re-renders that one page, not the whole caderno;
 * - **per notebook**, keyed by the sequence of those page keys, in tree
 *   order — so an added, edited, renamed, moved or deleted page produces a new
 *   key on its own. Nothing has to remember to flush anything.
 *
 * Both keys hash CONTENT, deliberately not `updated_at`: timestamps are stored
 * at second resolution, so a search landing in the same second as an edit
 * would cache an index that the edit can no longer invalidate — and with a
 * multi-day TTL, that staleness would outlive the day it started in.
 *
 * Both layers hold the entry's text twice (original + accent-folded), because
 * highlight offsets are computed on the folded copy and applied to the
 * original. That is the deliberate trade: memory for exact, accent-insensitive
 * highlighting. If a shared corpus ever outgrows it, the replacement is an
 * inverted index (term → entry ids), not a smaller cache.
 */
class DocumentationSearchService
{
    /** Results returned in one response. The palette is a jump list, not a report. */
    public const MAX_RESULTS = 50;

    /**
     * Content facets — what a passage CONTAINS, which is the axis that makes
     * the corpus behave like a dataset ("every section with a table").
     * Machine values; the labels a visitor reads live in the palette view.
     */
    public const TAGS = ['table', 'code', 'image', 'callout', 'file', 'diagram'];

    /** Characters of context kept on each side of the first hit in a snippet. */
    private const SNIPPET_RADIUS = 80;

    /** Total length of a snippet, hit included. */
    private const SNIPPET_LENGTH = 240;

    /** Bumped whenever the entry shape changes — old cache entries then simply miss. */
    // Bumped to v3 with the container swap: the index key hashed the
    // container's CLASS alongside its id, so every key a `Solution`-owned
    // corpus wrote is unreachable — and, worse, a notebook could collide with
    // whatever id its old container happened to have. Old entries expire on
    // their own TTL; nothing reads them again.
    private const VERSION = 'v3';

    /**
     * Accent folding, one character in for one character out, so a character
     * offset in the folded copy is the same offset in the original. A
     * transliteration that expands (æ → ae) would shift every highlight after
     * it by one.
     */
    private const ACCENT_MAP = [
        'á' => 'a', 'à' => 'a', 'â' => 'a', 'ã' => 'a', 'ä' => 'a', 'å' => 'a',
        'é' => 'e', 'è' => 'e', 'ê' => 'e', 'ë' => 'e',
        'í' => 'i', 'ì' => 'i', 'î' => 'i', 'ï' => 'i',
        'ó' => 'o', 'ò' => 'o', 'ô' => 'o', 'õ' => 'o', 'ö' => 'o',
        'ú' => 'u', 'ù' => 'u', 'û' => 'u', 'ü' => 'u',
        'ç' => 'c', 'ñ' => 'n', 'ý' => 'y', 'ÿ' => 'y',
    ];

    public function __construct(private readonly DocumentationPageService $pages) {}

    /** Lowercased and accent-folded, preserving character count (see ACCENT_MAP). */
    public static function fold(string $value): string
    {
        return strtr(mb_strtolower($value), self::ACCENT_MAP);
    }

    /**
     * The notebook's whole corpus, in reading order.
     *
     * @return array<int, array<string, mixed>>
     */
    public function index(Notebook $notebook): array
    {
        [$key, $tree] = $this->indexKey($notebook);

        return Cache::remember($key, now()->addDays(7), fn (): array => $this->build($tree));
    }

    /**
     * Whether the corpus is already indexed.
     *
     * The panel above the documentation renders its filter chips server-side
     * so they are on screen with the page, which means the page render would
     * otherwise inherit the index build — six seconds, on the largest corpus
     * measured. It doesn't: a cold index makes the panel render a placeholder
     * and the client fetch it, so the FIRST visit to a big shared link pays
     * that cost in the background instead of in time-to-first-paint, and every
     * visit after it renders the chips inline.
     */
    public function isWarm(Notebook $notebook): bool
    {
        return Cache::has($this->indexKey($notebook)[0]);
    }

    /**
     * The cache key for a notebook's index, plus the tree it was derived from
     * (so a caller that goes on to build doesn't query for it twice).
     *
     * One query for the tree, and the fingerprint is read straight off the
     * models it already hydrated — checking whether the index is stale costs
     * no extra query. Reading order is part of the fingerprint: a page that
     * MOVES changes the breadcrumb of every page under it.
     *
     * @return array{0: string, 1: Collection<int, array<string, mixed>>}
     */
    private function indexKey(Notebook $notebook): array
    {
        $tree = $this->pages->tree($notebook);

        $fingerprint = md5($tree
            ->map(fn (array $row): string => $this->pageKey($row['page']))
            ->implode('|'));

        return [
            self::VERSION . ':docs-search:index:' . $notebook->getKey() . ':' . $fingerprint,
            $tree,
        ];
    }

    /**
     * Runs a query over the corpus and returns the palette's whole payload:
     * ranked results with highlight segments, facet counts, and the corpus
     * totals shown in the footer.
     *
     * An EMPTY query is a first-class case, not a no-op: it returns the corpus
     * in reading order, which is what makes the palette double as a browsable
     * index of everything shared under the link.
     *
     * @param  array<string, mixed>  $filters
     * @return array<string, mixed>
     */
    public function search(Notebook $notebook, string $query, array $filters = []): array
    {
        $entries = $this->index($notebook);
        $terms = $this->terms($query);

        $matched = $terms === []
            ? $entries
            : array_values(array_filter($entries, fn (array $e): bool => $this->matches($e, $terms)));

        $section = $this->filterValue($filters, 'section');
        $tag = $this->filterValue($filters, 'tag');

        // Which chips EXIST comes from the whole corpus; what each one COUNTS
        // comes from the current result set, with the other dimension applied
        // — so a count reads as "what I'd get if I clicked this" while the row
        // itself stays put instead of growing and collapsing under the caret
        // as the visitor types.
        $facets = [
            'sections' => $this->sectionFacets($entries, $this->applyTag($matched, $tag)),
            'tags'     => $this->tagFacets($entries, $this->applySection($matched, $section)),
        ];

        $filtered = $this->applyTag($this->applySection($matched, $section), $tag);

        if ($terms !== []) {
            $scored = array_map(fn (array $e): array => $e + ['score' => $this->score($e, $terms)], $filtered);
            usort($scored, fn (array $a, array $b): int => [$b['score'], $a['order']] <=> [$a['score'], $b['order']]);
            $filtered = $scored;
        }

        $total = count($filtered);
        $results = array_map(
            fn (array $e): array => $this->result($e, $terms),
            array_slice($filtered, 0, self::MAX_RESULTS),
        );

        return [
            'query' => $query,
            // Echoed back so the view can mark the active chip without the
            // caller having to thread the raw request through a second path.
            'filters'  => ['section' => $section, 'tag' => $tag],
            'total'    => $total,
            'shown'    => count($results),
            'results'  => $results,
            'facets'   => $facets,
            'overview' => $this->overview($entries),
        ];
    }

    /*
    |--------------------------------------------------------------------------
    | Building the index
    |--------------------------------------------------------------------------
    */

    /**
     * @param  Collection<int, array<string, mixed>>  $tree
     * @return array<int, array<string, mixed>>
     */
    private function build(Collection $tree): array
    {
        /** @var array<int, DocumentationPage> $pages */
        $pages = $tree->map(fn (array $row): DocumentationPage => $row['page'])->all();

        $keys = [];
        foreach ($pages as $page) {
            $keys[$page->id] = $this->pageKey($page);
        }

        $cached = $keys === [] ? [] : Cache::many(array_values($keys));
        $misses = [];

        // Ancestor titles, so a result can say where it lives without a
        // second walk. `tree()` is in reading order, so a page's parent has
        // always been seen by the time the page is.
        $trails = [];
        $roots = [];
        $entries = [];
        $order = 0;

        foreach ($tree as $row) {
            /** @var DocumentationPage $page */
            $page = $row['page'];

            $parentTrail = $page->parent_id !== null ? ($trails[$page->parent_id] ?? []) : [];
            $trails[$page->id] = [...$parentTrail, $page->title];
            $roots[$page->id] = $page->parent_id !== null
                ? ($roots[$page->parent_id] ?? ['slug' => $page->slug, 'label' => $page->title])
                : ['slug' => $page->slug, 'label' => $page->title];

            $parsed = $cached[$keys[$page->id]] ?? null;
            if ($parsed === null) {
                $parsed = $this->parse($page);
                $misses[$keys[$page->id]] = $parsed;
            }

            $pageTags = $page->diagram_id !== null ? ['diagram'] : [];

            $entries[] = $this->entry(
                order: $order++,
                kind: 'page',
                title: $page->title,
                page: $page,
                trail: $parentTrail,
                root: $roots[$page->id],
                anchor: null,
                level: 0,
                text: $parsed['lead']['text'],
                tags: array_values(array_unique([...$pageTags, ...$parsed['lead']['tags']])),
            );

            foreach ($parsed['sections'] as $sectionData) {
                $entries[] = $this->entry(
                    order: $order++,
                    kind: 'section',
                    title: $sectionData['heading'],
                    page: $page,
                    trail: $trails[$page->id],
                    root: $roots[$page->id],
                    anchor: $sectionData['anchor'],
                    level: $sectionData['level'],
                    text: $sectionData['text'],
                    tags: $sectionData['tags'],
                );
            }
        }

        if ($misses !== []) {
            Cache::putMany($misses, now()->addDays(30));
        }

        return $entries;
    }

    /**
     * @param  array{slug: string, label: string}  $root
     * @param  array<int, string>  $trail
     * @param  array<int, string>  $tags
     * @return array<string, mixed>
     */
    private function entry(
        int $order,
        string $kind,
        string $title,
        DocumentationPage $page,
        array $trail,
        array $root,
        ?string $anchor,
        int $level,
        string $text,
        array $tags,
    ): array {
        return [
            'order'     => $order,
            'kind'      => $kind,
            'title'     => $title,
            'pageTitle' => $page->title,
            'slug'      => $page->slug,
            'anchor'    => $anchor,
            'level'     => $level,
            'trail'     => $trail,
            'root'      => $root['slug'],
            'rootLabel' => $root['label'],
            'tags'      => $tags,
            'text'      => $text,
            'words'     => $text === '' ? 0 : count(preg_split('/\s+/u', $text, -1, PREG_SPLIT_NO_EMPTY) ?: []),
            'foldTitle' => self::fold($title),
            'foldText'  => self::fold($text),
            'foldTrail' => self::fold(implode(' ', [...$trail, $page->title])),
        ];
    }

    /**
     * Content-addressed: everything the parsed payload depends on goes into the
     * hash, so a stale entry is unreachable rather than merely short-lived.
     */
    private function pageKey(DocumentationPage $page): string
    {
        return self::VERSION . ':docs-search:page:' . $page->id . ':' . md5(implode("\0", [
            $page->title,
            $page->slug,
            (string) $page->diagram_id,
            (string) $page->documentation,
        ]));
    }

    /**
     * Splits one page's rendered HTML into its lead and its H1–H3 sections.
     *
     * @return array{lead: array{text: string, tags: array<int, string>}, sections: array<int, array<string, mixed>>}
     */
    private function parse(DocumentationPage $page): array
    {
        $html = (new GitbookRenderer)->render($page->documentation);
        $empty = ['lead' => ['text' => '', 'tags' => []], 'sections' => []];

        if (trim($html) === '') {
            return $empty;
        }

        $dom = new DOMDocument;
        $previous = libxml_use_internal_errors(true);
        // The meta tag is what makes DOMDocument read the string as UTF-8; the
        // wrapper gives the walk below a single, predictable parent.
        $dom->loadHTML('<meta http-equiv="Content-Type" content="text/html; charset=utf-8"><div id="ak-doc-root">' . $html . '</div>');
        libxml_clear_errors();
        libxml_use_internal_errors($previous);

        $root = $dom->getElementById('ak-doc-root');

        if ($root === null) {
            return $empty;
        }

        $lead = ['nodes' => [], 'heading' => null, 'anchor' => null, 'level' => 0];
        $buckets = [$lead];

        foreach (iterator_to_array($root->childNodes) as $node) {
            if ($node instanceof DOMElement && preg_match('/^h([123])$/', strtolower($node->nodeName), $match)) {
                $buckets[] = [
                    'nodes'   => [],
                    'heading' => $this->headingText($node),
                    'anchor'  => $this->headingAnchor($node),
                    'level'   => (int) $match[1],
                ];

                continue;
            }

            $buckets[count($buckets) - 1]['nodes'][] = $node;
        }

        $parsed = array_map(fn (array $bucket): array => [
            'heading' => $bucket['heading'],
            'anchor'  => $bucket['anchor'],
            'level'   => $bucket['level'],
            ...$this->readNodes($dom, $bucket['nodes']),
        ], $buckets);

        $leadBucket = array_shift($parsed);

        // A heading with no anchor can't be linked to, so it stays folded into
        // the page rather than becoming an unreachable result.
        $sections = array_values(array_filter($parsed, fn (array $s): bool => $s['anchor'] !== null));

        // GitBook pages open with an H1 repeating the page's own title, and
        // that duplicate would otherwise be TWO results pointing at the same
        // place — one "pág", one "h1", same words. When the page starts with
        // it (nothing above it) the H1 IS the page, so it becomes the page's
        // own body instead of a section beside it.
        $opensWithTitle = $sections !== []
            && $leadBucket['text'] === ''
            && $sections[0]['level'] === 1
            && self::fold($sections[0]['heading']) === self::fold($page->title);

        if ($opensWithTitle) {
            $absorbed = array_shift($sections);
            $leadBucket = ['text' => $absorbed['text'], 'tags' => $absorbed['tags']];
        }

        return [
            'lead'     => ['text' => $leadBucket['text'], 'tags' => $leadBucket['tags']],
            'sections' => $sections,
        ];
    }

    /**
     * Plain text plus content facets for one bucket of nodes.
     *
     * The heading is deliberately NOT part of this text: it is already the
     * entry's title, and a snippet built from a body that starts by repeating
     * the title reads as an echo. Searching still finds it — `matches()`
     * scans the title too.
     *
     * @param  array<int, \DOMNode>  $nodes
     * @return array{text: string, tags: array<int, string>}
     */
    private function readNodes(DOMDocument $dom, array $nodes): array
    {
        $text = '';
        $html = '';

        foreach ($nodes as $node) {
            $text .= $node->textContent . ' ';
            $html .= (string) $dom->saveHTML($node);
        }

        $tags = [];
        foreach ([
            'table'   => '<table',
            'code'    => '<pre',
            'image'   => '<img',
            'callout' => 'data-callout',
            'file'    => 'ak-doc-file',
        ] as $tag => $needle) {
            if (str_contains($html, $needle)) {
                $tags[] = $tag;
            }
        }

        return ['text' => $this->collapse($text), 'tags' => $tags];
    }

    /** The heading's own words, without the `#` permalink commonmark appends. */
    private function headingText(DOMElement $heading): string
    {
        $clone = $heading->cloneNode(true);

        foreach (iterator_to_array($clone->getElementsByTagName('a')) as $link) {
            if ($link instanceof DOMElement && str_contains($link->getAttribute('class'), 'heading-permalink')) {
                $link->parentNode?->removeChild($link);
            }
        }

        return $this->collapse($clone->textContent);
    }

    /** The permalink id emitted by `HeadingPermalinkExtension` — the `#fragment` a result links to. */
    private function headingAnchor(DOMElement $heading): ?string
    {
        foreach ($heading->getElementsByTagName('a') as $link) {
            if (str_contains($link->getAttribute('class'), 'heading-permalink') && $link->getAttribute('id') !== '') {
                return $link->getAttribute('id');
            }
        }

        return null;
    }

    private function collapse(string $text): string
    {
        return trim((string) preg_replace('/\s+/u', ' ', $text));
    }

    /*
    |--------------------------------------------------------------------------
    | Querying
    |--------------------------------------------------------------------------
    */

    /** @return array<int, string> */
    private function terms(string $query): array
    {
        return array_values(array_filter(
            preg_split('/\s+/u', self::fold(trim($query)), -1, PREG_SPLIT_NO_EMPTY) ?: [],
            fn (string $term): bool => $term !== '',
        ));
    }

    /**
     * Every term has to appear SOMEWHERE in the entry (title, breadcrumb or
     * body) — typing more words narrows, it never widens.
     *
     * @param  array<string, mixed>  $entry
     * @param  array<int, string>  $terms
     */
    private function matches(array $entry, array $terms): bool
    {
        $haystack = $entry['foldTitle'] . ' ' . $entry['foldTrail'] . ' ' . $entry['foldText'];

        foreach ($terms as $term) {
            if (! str_contains($haystack, $term)) {
                return false;
            }
        }

        return true;
    }

    /**
     * @param  array<string, mixed>  $entry
     * @param  array<int, string>  $terms
     */
    private function score(array $entry, array $terms): int
    {
        $phrase = implode(' ', $terms);
        $title = $entry['foldTitle'];
        $score = 0;

        if ($title === $phrase) {
            $score += 1000;
        } elseif (str_starts_with($title, $phrase)) {
            $score += 700;
        } elseif (str_contains($title, $phrase)) {
            $score += 450;
        }

        foreach ($terms as $term) {
            if (str_contains($title, $term)) {
                $score += 120;
            }
            if (str_contains($entry['foldTrail'], $term)) {
                $score += 25;
            }
            if (str_contains($entry['foldText'], $term)) {
                $score += 20;
            }
        }

        // A page outranks its own sections on an equal footing: someone
        // searching a page's name wants the page, not its third heading.
        if ($entry['kind'] === 'page') {
            $score += 40;
        }

        return $score;
    }

    /**
     * @param  array<string, mixed>  $entry
     * @param  array<int, string>  $terms
     * @return array<string, mixed>
     */
    private function result(array $entry, array $terms): array
    {
        return [
            'kind'    => $entry['kind'],
            'level'   => $entry['level'],
            'title'   => $this->highlight($entry['title'], $this->ranges($entry['foldTitle'], $terms)),
            'page'    => $entry['pageTitle'],
            'trail'   => $entry['trail'],
            'slug'    => $entry['slug'],
            'anchor'  => $entry['anchor'],
            'tags'    => $entry['tags'],
            'words'   => $entry['words'],
            'snippet' => $this->snippet($entry['text'], $entry['foldText'], $terms),
        ];
    }

    /**
     * Non-overlapping match ranges, as CHARACTER offsets valid in both the
     * folded copy and the original (see ACCENT_MAP).
     *
     * @param  array<int, string>  $terms
     * @return array<int, array{0: int, 1: int}>
     */
    private function ranges(string $folded, array $terms): array
    {
        $ranges = [];

        foreach ($terms as $term) {
            $length = mb_strlen($term);
            $offset = 0;

            while (($position = mb_strpos($folded, $term, $offset)) !== false) {
                $ranges[] = [$position, $position + $length];
                $offset = $position + $length;

                if (count($ranges) >= 60) {
                    break 2;
                }
            }
        }

        usort($ranges, fn (array $a, array $b): int => $a[0] <=> $b[0]);

        $merged = [];
        foreach ($ranges as $range) {
            $last = $merged === [] ? null : $merged[count($merged) - 1];

            if ($last !== null && $range[0] <= $last[1]) {
                $merged[count($merged) - 1][1] = max($last[1], $range[1]);

                continue;
            }

            $merged[] = $range;
        }

        return $merged;
    }

    /**
     * Text split into consecutive `{text, match}` segments. The client renders
     * `match` inside `<mark>` — it never receives HTML, so nothing authored in
     * a page can reach the palette as markup.
     *
     * @param  array<int, array{0: int, 1: int}>  $ranges
     * @return array<int, array{text: string, match: bool}>
     */
    private function highlight(string $text, array $ranges): array
    {
        $segments = [];
        $cursor = 0;

        foreach ($ranges as [$start, $end]) {
            if ($start > $cursor) {
                $segments[] = ['text' => mb_substr($text, $cursor, $start - $cursor), 'match' => false];
            }
            $segments[] = ['text' => mb_substr($text, $start, $end - $start), 'match' => true];
            $cursor = $end;
        }

        if ($cursor < mb_strlen($text)) {
            $segments[] = ['text' => mb_substr($text, $cursor), 'match' => false];
        }

        return $segments;
    }

    /**
     * A window of the body around the FIRST hit, so the visitor reads the
     * sentence the term is in rather than the entry's opening words.
     *
     * @param  array<int, string>  $terms
     * @return array<int, array{text: string, match: bool}>
     */
    private function snippet(string $text, string $folded, array $terms): array
    {
        if ($text === '') {
            return [];
        }

        $ranges = $this->ranges($folded, $terms);
        $length = mb_strlen($text);
        $start = $ranges === [] ? 0 : max(0, $ranges[0][0] - self::SNIPPET_RADIUS);
        $end = min($length, $start + self::SNIPPET_LENGTH);

        $window = mb_substr($text, $start, $end - $start);

        $shifted = [];
        foreach ($ranges as [$rangeStart, $rangeEnd]) {
            if ($rangeEnd <= $start || $rangeStart >= $end) {
                continue;
            }
            $shifted[] = [max(0, $rangeStart - $start), min($end, $rangeEnd) - $start];
        }

        $segments = $this->highlight($window, $shifted);

        if ($start > 0) {
            array_unshift($segments, ['text' => '… ', 'match' => false]);
        }
        if ($end < $length) {
            $segments[] = ['text' => ' …', 'match' => false];
        }

        return $segments;
    }

    /*
    |--------------------------------------------------------------------------
    | Facets
    |--------------------------------------------------------------------------
    */

    /**
     * @param  array<string, mixed>  $filters
     */
    private function filterValue(array $filters, string $key): ?string
    {
        $value = $filters[$key] ?? null;

        return is_string($value) && $value !== '' ? $value : null;
    }

    /**
     * @param  array<int, array<string, mixed>>  $entries
     * @return array<int, array<string, mixed>>
     */
    private function applySection(array $entries, ?string $section): array
    {
        return $section === null
            ? $entries
            : array_values(array_filter($entries, fn (array $e): bool => $e['root'] === $section));
    }

    /**
     * @param  array<int, array<string, mixed>>  $entries
     * @return array<int, array<string, mixed>>
     */
    private function applyTag(array $entries, ?string $tag): array
    {
        return $tag === null
            ? $entries
            : array_values(array_filter($entries, fn (array $e): bool => in_array($tag, $e['tags'], true)));
    }

    /**
     * @param  array<int, array<string, mixed>>  $universe  every entry — decides which chips exist
     * @param  array<int, array<string, mixed>>  $matched  the current result set — decides the counts
     * @return array<int, array{value: string, label: string, count: int}>
     */
    private function sectionFacets(array $universe, array $matched): array
    {
        $facets = [];

        // Reading order, because the chips read as the documentation's own
        // top-level table of contents.
        foreach ($universe as $entry) {
            $facets[$entry['root']] ??= ['value' => $entry['root'], 'label' => $entry['rootLabel'], 'count' => 0];
        }

        foreach ($matched as $entry) {
            $facets[$entry['root']]['count']++;
        }

        return array_values($facets);
    }

    /**
     * @param  array<int, array<string, mixed>>  $universe
     * @param  array<int, array<string, mixed>>  $matched
     * @return array<int, array{value: string, count: int}>
     */
    private function tagFacets(array $universe, array $matched): array
    {
        $present = [];
        foreach ($universe as $entry) {
            foreach ($entry['tags'] as $tag) {
                $present[$tag] = true;
            }
        }

        $counts = array_fill_keys(self::TAGS, 0);
        foreach ($matched as $entry) {
            foreach ($entry['tags'] as $tag) {
                $counts[$tag] = ($counts[$tag] ?? 0) + 1;
            }
        }

        return array_values(array_map(
            fn (string $tag): array => ['value' => $tag, 'count' => $counts[$tag]],
            array_values(array_filter(self::TAGS, fn (string $tag): bool => isset($present[$tag]))),
        ));
    }

    /**
     * @param  array<int, array<string, mixed>>  $entries
     * @return array{pages: int, sections: int, words: int}
     */
    private function overview(array $entries): array
    {
        return [
            'pages'    => count(array_filter($entries, fn (array $e): bool => $e['kind'] === 'page')),
            'sections' => count(array_filter($entries, fn (array $e): bool => $e['kind'] === 'section')),
            'words'    => array_sum(array_column($entries, 'words')),
        ];
    }
}
