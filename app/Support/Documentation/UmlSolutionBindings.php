<?php

namespace App\Support\Documentation;

use App\Models\Solution;
use App\Support\Fold;
use App\Support\GitbookRenderer;

/**
 * Reads the catalog solutions a page's UML diagrams are bound to, and the
 * relationships those diagrams state between them.
 *
 * A participant, an element or a group carries `"solution": "<slug>"`
 * (UmlDiagram checks the shape only — it never asks the database). This class
 * resolves the slugs, in ONE query per body, and turns each diagram into link
 * rows (`uml_solution_links`):
 *
 * - a sequence message `sync`/`async`/`create`/`destroy` from one solution to
 *   another says the first CALLS the second. A `return` is the answer to a
 *   call, not a call, and a gate has only one end on a participant: neither
 *   counts.
 * - a structural edge `directed`/`dependency`/`flow` says the same. An
 *   `association` (a line with no arrowhead) is a link with NO direction. The
 *   rest (inheritance, composition, include…) state structure, not
 *   communication.
 * - a grid element with no binding of its own takes the innermost bound GROUP
 *   it sits in — an iFlow drawn inside an "SAP CPI" boundary is SAP CPI.
 *
 * Two ends on the same solution say nothing about the catalog and are
 * dropped. An unknown slug derives nothing: the reader draws the diagram
 * anyway (a deleted solution must not break a page), the editor's check and
 * the MCP writes say which slug is wrong.
 *
 * The rows are evidence, not pairs: SolutionRelationshipService folds them
 * into one relationship per pair, and infers "via" a platform, on read.
 */
final class UmlSolutionBindings
{
    /** Edge kinds that state a CALL from `from` to `to`. */
    public const DIRECTED_EDGES = ['directed', 'dependency', 'flow'];

    /** Edge kinds that state a link with no direction. */
    public const UNDIRECTED_EDGES = ['association'];

    public function __construct(private readonly GitbookRenderer $renderer) {}

    /**
     * Every slug a parsed diagram names, deduplicated.
     *
     * @param  array<string, mixed>|null  $diagram  UmlDiagram::parse()'s `diagram`
     * @return list<string>
     */
    public static function slugsOf(?array $diagram): array
    {
        if ($diagram === null) {
            return [];
        }

        $items = [...($diagram['participants'] ?? []), ...($diagram['nodes'] ?? []), ...($diagram['groups'] ?? [])];

        return array_values(array_unique(array_filter(array_column($items, 'solution'), 'is_string')));
    }

    /**
     * The link rows a page body states, without the page id: one per call or
     * association between two DIFFERENT, existing solutions.
     *
     * @return list<array{from_solution_id: int, to_solution_id: int, directed: bool, diagram_position: int, ordinal: int, diagram_type: string, diagram_title: ?string, label: ?string}>
     */
    public function links(?string $markdown): array
    {
        $diagrams = $this->diagrams((string) $markdown);
        $slugs = array_unique(array_merge([], ...array_map(fn (array $d) => self::slugsOf($d['diagram']), $diagrams)));

        if ($slugs === []) {
            return [];
        }

        $ids = Solution::query()->whereIn('slug', $slugs)->pluck('id', 'slug')->all();
        $rows = [];

        foreach ($diagrams as ['position' => $position, 'diagram' => $diagram]) {
            if ($diagram === null) {
                continue;
            }

            $pairs = $diagram['type'] === 'sequence'
                ? $this->sequenceLinks($diagram, $ids)
                : $this->gridLinks($diagram, $ids);

            foreach ($pairs as $pair) {
                $rows[] = [
                    ...$pair,
                    'diagram_position' => $position,
                    'diagram_type'     => $diagram['type'],
                    'diagram_title'    => $diagram['title'] !== '' ? $diagram['title'] : null,
                ];
            }
        }

        return $rows;
    }

    /**
     * What the editor's "Diagrama UML" block is told about ONE diagram's
     * bindings: the slugs the catalog does not have (a problem — the binding
     * would derive nothing), and, as a HINT, an unbound element whose name is
     * a solution's name ("parece ser…"). A hint never refuses anything: a
     * name is not a binding (EndoFlow's "API" is not a catalog system), but
     * the author who meant one should not have to look the slug up.
     *
     * @param  array<string, mixed>  $diagram  UmlDiagram::parse()'s `diagram`
     * @return array{problems: list<string>, hints: list<string>}
     */
    public function check(array $diagram): array
    {
        $solutions = Solution::query()->get(['id', 'name', 'slug']);
        $known = $solutions->pluck('slug')->all();
        $problems = [];

        foreach (self::slugsOf($diagram) as $slug) {
            if (! in_array($slug, $known, true)) {
                $problems[] = "A solução \"{$slug}\" não existe no catálogo.";
            }
        }

        $byName = [];
        foreach ($solutions as $solution) {
            $byName[Fold::text($solution->name)] ??= $solution;
            // "Digibee (iPaaS)" is called "Digibee" in every diagram.
            $byName[Fold::text(trim((string) preg_replace('/\s*\(.*?\)\s*/u', ' ', $solution->name)))] ??= $solution;
        }

        $hints = [];
        $unbound = $diagram['type'] === 'sequence'
            ? array_map(fn (array $p) => isset($p['solution']) ? null : $p['label'], $diagram['participants'])
            : [
                ...array_map(fn (array $n) => isset($n['solution']) || ! in_array($n['kind'], UmlDiagram::BINDABLE_KINDS, true)
                    ? null : ($n['name'] ?? null), $diagram['nodes']),
                ...array_map(fn (array $g) => isset($g['solution']) ? null : $g['label'], $diagram['groups']),
            ];

        foreach (array_unique(array_filter($unbound, fn ($name) => is_string($name) && $name !== '')) as $name) {
            if ($match = $byName[Fold::text($name)] ?? null) {
                $hints[] = "\"{$name}\" parece ser a solução {$match->name} — vincule com \"solution\": \"{$match->slug}\".";
            }
        }

        return ['problems' => $problems, 'hints' => $hints];
    }

    /**
     * The page's UML blocks, in order, parsed — valid or not, since
     * `position` has to count them the way the renderer numbers its figures.
     *
     * @return list<array{position: int, diagram: ?array<string, mixed>}>
     */
    private function diagrams(string $markdown): array
    {
        $out = [];
        $position = 0;

        foreach ($this->renderer->figureBlocks($markdown) as $block) {
            if ($block['kind'] !== 'uml') {
                continue;
            }

            $title = trim(html_entity_decode($block['attrs']['title'] ?? '', ENT_QUOTES));
            $out[] = [
                'position' => ++$position,
                'diagram'  => UmlDiagram::parse($block['attrs']['type'] ?? '', $title, implode("\n", $block['inner']))['diagram'],
            ];
        }

        return $out;
    }

    /**
     * @param  array<string, mixed>  $diagram
     * @param  array<string, int>  $ids  slug → solution id
     * @return list<array{from_solution_id: int, to_solution_id: int, directed: bool, ordinal: int, label: ?string}>
     */
    private function sequenceLinks(array $diagram, array $ids): array
    {
        $bound = [];
        foreach ($diagram['participants'] as $participant) {
            if (isset($participant['solution'], $ids[$participant['solution']])) {
                $bound[$participant['id']] = $ids[$participant['solution']];
            }
        }

        $rows = [];
        $ordinal = 0;
        $this->walk($diagram['messages'], $bound, $ordinal, $rows);

        return $rows;
    }

    /**
     * Messages in reading order, depth first through fragment operands — the
     * order `ordinal` records, which is what tells "X called the platform,
     * then the platform called Y" apart from the reverse.
     *
     * @param  list<array<string, mixed>>  $items
     * @param  array<string, int>  $bound  participant id → solution id
     * @param  list<array<string, mixed>>  $rows
     */
    private function walk(array $items, array $bound, int &$ordinal, array &$rows): void
    {
        foreach ($items as $item) {
            if (isset($item['fragment'])) {
                foreach ($item['operands'] as $operand) {
                    $this->walk($operand['messages'], $bound, $ordinal, $rows);
                }

                continue;
            }

            if (! isset($item['kind'])) {
                continue; // a note, a ref, a state, a divider
            }

            $ordinal++;
            $from = $bound[$item['from'] ?? ''] ?? null;
            $to = $bound[$item['to'] ?? ''] ?? null;

            if ($item['kind'] === 'return' || ($item['gate'] ?? false) || $from === null || $to === null || $from === $to) {
                continue;
            }

            $rows[] = [
                'from_solution_id' => $from,
                'to_solution_id'   => $to,
                'directed'         => true,
                'ordinal'          => $ordinal,
                'label'            => ($item['label'] ?? '') !== '' ? $item['label'] : null,
            ];
        }
    }

    /**
     * @param  array<string, mixed>  $diagram
     * @param  array<string, int>  $ids  slug → solution id
     * @return list<array{from_solution_id: int, to_solution_id: int, directed: bool, ordinal: int, label: ?string}>
     */
    private function gridLinks(array $diagram, array $ids): array
    {
        // Innermost first: a group nested in another holds fewer elements.
        $groups = collect($diagram['groups'])
            ->filter(fn (array $group) => isset($group['solution'], $ids[$group['solution']]))
            ->sortBy(fn (array $group) => count($group['nodes']))
            ->values();

        $bound = [];
        foreach ($diagram['nodes'] as $node) {
            $own = isset($node['solution']) ? ($ids[$node['solution']] ?? null) : null;
            $group = $groups->first(fn (array $g) => in_array($node['id'], $g['nodes'], true));
            $solution = $own ?? ($group !== null ? $ids[$group['solution']] : null);

            if ($solution !== null) {
                $bound[$node['id']] = $solution;
            }
        }

        $rows = [];

        foreach ($diagram['edges'] as $i => $edge) {
            $from = $bound[$edge['from']] ?? null;
            $to = $bound[$edge['to']] ?? null;
            $directed = in_array($edge['kind'], self::DIRECTED_EDGES, true);

            if ($from === null || $to === null || $from === $to
                || (! $directed && ! in_array($edge['kind'], self::UNDIRECTED_EDGES, true))) {
                continue;
            }

            // An undirected pair has ONE spelling: the lower id first.
            if (! $directed && $from > $to) {
                [$from, $to] = [$to, $from];
            }

            $rows[] = [
                'from_solution_id' => $from,
                'to_solution_id'   => $to,
                'directed'         => $directed,
                'ordinal'          => $i + 1,
                'label'            => ($edge['label'] ?? '') !== '' ? $edge['label'] : null,
            ];
        }

        return $rows;
    }
}
