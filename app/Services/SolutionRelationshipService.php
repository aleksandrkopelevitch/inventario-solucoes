<?php

namespace App\Services;

use App\Enums\RelationshipDirection;
use App\Models\DocumentationPage;
use App\Models\Solution;
use App\Models\UmlSolutionLink;
use App\Support\Fold;
use App\Support\Relationships\Relationship;
use Illuminate\Support\Collection;

/**
 * Who talks to whom in the catalog, as the documentation's UML diagrams say
 * it (docs-and-specs/solution-relationships-from-uml.md).
 *
 * `uml_solution_links` holds the EVIDENCE — one row per call or association a
 * diagram states. This folds it into one relationship per pair of solutions:
 *
 * - calls drawn both ways make the pair bidirectional, one way a direction,
 *   and only plain associations a pair with no direction;
 * - a call that crosses an integration PLATFORM (category `ipaas` — Digibee,
 *   SAP CPI) also yields the end-to-end pair, "via" that platform. Inferred
 *   inside ONE diagram and never across two: Digibee connects dozens of
 *   systems, and joining unrelated pages through it would invent
 *   integrations. In a sequence the ORDER decides (X called the platform, and
 *   the platform's next outgoing call before anybody else called it went to
 *   Y); in a structural diagram only an unambiguous fan-in or fan-out does — a
 *   hub drawn as one picture does not say whether A reaches C or D.
 *
 * Platform inference runs on read, so changing a solution's category changes
 * the answer at once, with no page to re-sync.
 */
class SolutionRelationshipService
{
    public const PLATFORM_CATEGORY = 'ipaas';

    public const MAX_LABELS = 5;

    /** At most this many platforms in the middle of one inferred pair. */
    public const MAX_PLATFORM_HOPS = 2;

    private const SOLUTION_COLUMNS = ['id', 'name', 'slug', 'logo_path', 'category'];

    /**
     * Every relationship of `$solution`, oriented from it, sorted by the other
     * solution's name. Direct and inferred ("via") alike.
     *
     * @return Collection<int, Relationship>
     */
    public function forSolution(Solution $solution): Collection
    {
        $id = $solution->getKey();

        // The diagrams it appears in, whole: inferring "via" needs the other
        // calls of the same diagram, not only the rows that name it.
        $diagrams = UmlSolutionLink::query()
            ->where(fn ($q) => $q->where('from_solution_id', $id)->orWhere('to_solution_id', $id))
            ->distinct()
            ->get(['documentation_page_id', 'diagram_position'])
            ->map(fn (UmlSolutionLink $link) => "{$link->documentation_page_id}:{$link->diagram_position}")
            ->flip();

        if ($diagrams->isEmpty()) {
            return collect();
        }

        $rows = UmlSolutionLink::query()
            ->whereIn('documentation_page_id', $diagrams->keys()->map(fn (string $key) => (int) explode(':', $key)[0])->unique()->values())
            ->get()
            ->filter(fn (UmlSolutionLink $link) => $diagrams->has("{$link->documentation_page_id}:{$link->diagram_position}"));

        $pairs = array_filter($this->derive($rows, withVia: true), fn (array $pair) => $pair['a'] === $id || $pair['b'] === $id);

        return $this->relationships($pairs, $solution)
            ->sortBy(fn (Relationship $r) => Fold::text($r->other->name))
            ->values();
    }

    /**
     * Every DIRECT pair in the catalog, for the ecosystem map: the "via" pairs
     * would only repeat the two arrows that already meet at the platform.
     *
     * @return list<array{source: Solution, target: Solution, direction: string, labels: list<string>, evidence: list<array{page: DocumentationPage, position: int, title: ?string, type: string}>}>
     */
    public function pairs(): array
    {
        $pairs = $this->derive(UmlSolutionLink::query()->get(), withVia: false);
        $solutions = $this->solutions($pairs);
        $pages = $this->pages($pairs);

        return array_values(array_map(function (array $pair) use ($solutions, $pages) {
            // Oriented by what was drawn: a one-way pair runs source → target.
            $reversed = $pair['bToA'] && ! $pair['aToB'];

            return [
                'source'    => $solutions[$reversed ? $pair['b'] : $pair['a']],
                'target'    => $solutions[$reversed ? $pair['a'] : $pair['b']],
                'direction' => match (true) {
                    $pair['aToB'] && $pair['bToA'] => 'bidirectional',
                    $pair['aToB'] || $pair['bToA'] => 'unidirectional',
                    default                        => 'undirected',
                },
                'labels'   => $pair['labels'],
                'evidence' => $this->evidence($pair, $pages),
            ];
        }, $pairs));
    }

    /**
     * How many relationships (direct and inferred) each solution has — one
     * read of the whole index, for the catalog's spreadsheet.
     *
     * @return array<int, int> solution id → count
     */
    public function counts(): array
    {
        $counts = [];

        foreach ($this->derive(UmlSolutionLink::query()->get(), withVia: true) as $pair) {
            $counts[$pair['a']] = ($counts[$pair['a']] ?? 0) + 1;
            $counts[$pair['b']] = ($counts[$pair['b']] ?? 0) + 1;
        }

        return $counts;
    }

    /** How many pairs of solutions are related, directly or through a platform. */
    public function total(): int
    {
        return count($this->derive(UmlSolutionLink::query()->get(), withVia: true));
    }

    /* ------------------------------------------------------------------ */
    /*  Folding */
    /* ------------------------------------------------------------------ */

    /**
     * One entry per unordered pair, keyed "a|b" with a < b.
     *
     * @param  Collection<int, UmlSolutionLink>  $rows
     * @return array<string, array{a: int, b: int, aToB: bool, bToA: bool, direct: bool, via: list<int>, labels: list<string>, evidence: array<string, array{page: int, position: int, title: ?string, type: string}>}>
     */
    private function derive(Collection $rows, bool $withVia): array
    {
        $pairs = [];

        foreach ($rows as $row) {
            $this->add($pairs, $row->from_solution_id, $row->to_solution_id, $row->directed, $row, [$row->label]);
        }

        if (! $withVia || $rows->isEmpty()) {
            return $pairs;
        }

        $platforms = Solution::query()->where('category', self::PLATFORM_CATEGORY)->pluck('id')->flip()->all();

        if ($platforms === []) {
            return $pairs;
        }

        $byDiagram = $rows->filter(fn (UmlSolutionLink $row) => $row->directed)
            ->sortBy('ordinal')
            ->groupBy(fn (UmlSolutionLink $row) => "{$row->documentation_page_id}:{$row->diagram_position}");

        foreach ($byDiagram as $diagram) {
            $inferred = $diagram->first()->diagram_type === 'sequence'
                ? $this->viaSequence($diagram, $platforms)
                : $this->viaGrid($diagram, $platforms);

            foreach ($inferred as [$from, $to, $via, $labels]) {
                $this->add($pairs, $from, $to, true, $diagram->first(), $labels, $via);
            }
        }

        return $pairs;
    }

    /**
     * @param  array<string, array<string, mixed>>  $pairs
     * @param  list<?string>  $labels
     * @param  list<int>|null  $via  null for a call the diagram states directly
     */
    private function add(array &$pairs, int $from, int $to, bool $directed, UmlSolutionLink $row, array $labels, ?array $via = null): void
    {
        [$a, $b] = $from < $to ? [$from, $to] : [$to, $from];
        $key = "{$a}|{$b}";

        $pairs[$key] ??= ['a' => $a, 'b' => $b, 'aToB' => false, 'bToA' => false, 'direct' => false, 'via' => [], 'labels' => [], 'evidence' => []];
        $pair = &$pairs[$key];

        if ($directed) {
            $pair[$from === $a ? 'aToB' : 'bToA'] = true;
        }

        if ($via === null) {
            $pair['direct'] = true;
        } else {
            $pair['via'] = array_values(array_unique([...$pair['via'], ...$via]));
        }

        foreach ($labels as $label) {
            if (filled($label) && count($pair['labels']) < self::MAX_LABELS && ! in_array($label, $pair['labels'], true)) {
                $pair['labels'][] = $label;
            }
        }

        $pair['evidence']["{$row->documentation_page_id}:{$row->diagram_position}"] = [
            'page'     => $row->documentation_page_id,
            'position' => $row->diagram_position,
            'title'    => $row->diagram_title,
            'type'     => $row->diagram_type,
        ];
    }

    /**
     * X calls a platform, and the platform's next call out — before any other
     * caller reaches it — goes to Y: X → Y via the platform. A platform
     * handing the message to another platform carries the origin along, up to
     * MAX_PLATFORM_HOPS platforms in the middle.
     *
     * @param  Collection<int, UmlSolutionLink>  $rows  one diagram's directed calls, in order
     * @param  array<int, int>  $platforms  platform ids, as keys
     * @return list<array{0: int, 1: int, 2: list<int>, 3: list<?string>}>
     */
    private function viaSequence(Collection $rows, array $platforms): array
    {
        $serving = [];
        $out = [];

        foreach ($rows as $row) {
            $from = $row->from_solution_id;
            $to = $row->to_solution_id;
            $fromPlatform = isset($platforms[$from]);
            $toPlatform = isset($platforms[$to]);

            if (! $fromPlatform && $toPlatform) {
                $serving[$to] = ['origin' => $from, 'via' => [$to], 'labels' => [$row->label]];
            } elseif ($fromPlatform && $toPlatform) {
                if (isset($serving[$from]) && count($serving[$from]['via']) < self::MAX_PLATFORM_HOPS) {
                    $serving[$to] = [
                        'origin' => $serving[$from]['origin'],
                        'via'    => [...$serving[$from]['via'], $to],
                        'labels' => [...$serving[$from]['labels'], $row->label],
                    ];
                }
            } elseif ($fromPlatform && isset($serving[$from]) && $serving[$from]['origin'] !== $to) {
                $out[] = [$serving[$from]['origin'], $to, $serving[$from]['via'], [...$serving[$from]['labels'], $row->label]];
            }
        }

        return $out;
    }

    /**
     * Through a platform drawn as a box: every caller with every callee, but
     * only when one of the two sides is a single solution. A hub with several
     * on both sides infers nothing — the picture does not say who reaches
     * whom. A chain of two platforms (P1 → P2) is read the same way.
     *
     * @param  Collection<int, UmlSolutionLink>  $rows  one diagram's directed edges
     * @param  array<int, int>  $platforms  platform ids, as keys
     * @return list<array{0: int, 1: int, 2: list<int>, 3: list<?string>}>
     */
    private function viaGrid(Collection $rows, array $platforms): array
    {
        $calls = $rows->map(fn (UmlSolutionLink $row) => [$row->from_solution_id, $row->to_solution_id, $row->label]);
        $label = fn (int $from, int $to): ?string => $calls->first(fn (array $c) => $c[0] === $from && $c[1] === $to)[2] ?? null;

        // Each run of platforms: [P] alone, or [P1, P2] when one hands to the other.
        $runs = collect(array_keys($platforms))
            ->filter(fn (int $p) => $calls->contains(fn (array $c) => $c[0] === $p || $c[1] === $p))
            ->map(fn (int $p) => [$p])
            ->values()
            ->all();

        foreach ($calls as [$from, $to]) {
            if (isset($platforms[$from], $platforms[$to]) && self::MAX_PLATFORM_HOPS >= 2) {
                $runs[] = [$from, $to];
            }
        }

        $out = [];

        foreach ($runs as $run) {
            $first = $run[0];
            $last = $run[count($run) - 1];
            $callers = $calls->filter(fn (array $c) => $c[1] === $first && ! isset($platforms[$c[0]]))->pluck(0)->unique()->values();
            $callees = $calls->filter(fn (array $c) => $c[0] === $last && ! isset($platforms[$c[1]]))->pluck(1)->unique()->values();

            if ($callers->count() !== 1 && $callees->count() !== 1) {
                continue;
            }

            foreach ($callers as $x) {
                foreach ($callees as $y) {
                    if ($x !== $y) {
                        $out[] = [$x, $y, $run, [$label($x, $first), $label($last, $y)]];
                    }
                }
            }
        }

        return $out;
    }

    /* ------------------------------------------------------------------ */
    /*  Hydrating */
    /* ------------------------------------------------------------------ */

    /**
     * @param  array<string, array<string, mixed>>  $pairs
     * @return Collection<int, Relationship>
     */
    private function relationships(array $pairs, Solution $self): Collection
    {
        $solutions = $this->solutions($pairs);
        $pages = $this->pages($pairs);

        return collect($pairs)->map(function (array $pair) use ($self, $solutions, $pages) {
            $isA = $pair['a'] === $self->getKey();

            return new Relationship(
                solution: $self,
                other: $solutions[$isA ? $pair['b'] : $pair['a']],
                direction: RelationshipDirection::fromFlags(
                    out: $isA ? $pair['aToB'] : $pair['bToA'],
                    in: $isA ? $pair['bToA'] : $pair['aToB'],
                ),
                direct: $pair['direct'],
                via: array_values(array_filter(array_map(fn (int $id) => $solutions[$id] ?? null, $pair['via']))),
                labels: $pair['labels'],
                evidence: $this->evidence($pair, $pages),
            );
        })->values();
    }

    /**
     * @param  array<string, array<string, mixed>>  $pairs
     * @return array<int, Solution>
     */
    private function solutions(array $pairs): array
    {
        $ids = array_unique(array_merge([], ...array_map(fn (array $p) => [$p['a'], $p['b'], ...$p['via']], array_values($pairs))));

        return Solution::query()->whereIn('id', $ids)->get(self::SOLUTION_COLUMNS)->keyBy('id')->all();
    }

    /**
     * @param  array<string, array<string, mixed>>  $pairs
     * @return array<int, DocumentationPage>
     */
    private function pages(array $pairs): array
    {
        $ids = array_unique(array_merge([], ...array_map(fn (array $p) => array_column($p['evidence'], 'page'), array_values($pairs))));

        return DocumentationPage::query()
            ->with('notebook:id,slug,name,published_at')
            ->whereIn('id', $ids)
            ->get(['id', 'notebook_id', 'slug', 'title'])
            ->keyBy('id')
            ->all();
    }

    /**
     * @param  array<string, mixed>  $pair
     * @param  array<int, DocumentationPage>  $pages
     * @return list<array{page: DocumentationPage, position: int, title: ?string, type: string}>
     */
    private function evidence(array $pair, array $pages): array
    {
        return collect($pair['evidence'])
            ->filter(fn (array $e) => isset($pages[$e['page']]))
            ->map(fn (array $e) => [...$e, 'page' => $pages[$e['page']]])
            ->sortBy(fn (array $e) => Fold::text($e['page']->notebook->name . ' ' . $e['page']->title) . sprintf('%05d', $e['position']))
            ->values()
            ->all();
    }
}
