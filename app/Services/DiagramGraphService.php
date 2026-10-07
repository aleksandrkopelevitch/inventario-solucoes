<?php

namespace App\Services;

use App\Enums\ChainNodeKind;
use App\Enums\DiagramStatus;
use App\Enums\Direction;
use App\Enums\Protocol;
use App\Models\AttributeOption;
use App\Models\Diagram;
use App\Models\Solution;
use App\Support\CategoryPalette;
use App\Support\ChainLabeler;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Storage;

/**
 * Resolves the diagram graph into the NEUTRAL (renderer-agnostic)
 * contract described in section 10 of the briefing. The mapping to the
 * canvas schema happens on the client, so swapping the renderer in the
 * future doesn't touch the server.
 *
 * Return format:
 *
 *     [
 *         'nodes' => [ ['id','label','slug','category','logo','url',
 *             'categoryLabel','statusLabel','criticalityLabel','environmentLabel',
 *             'cloudLabel','contractLabel','supportLabel','directorate',
 *             'categoryFamily','hosting'], ... ],
 *         'edges' => [ ['id','source','target','label','status','direction','diagrams'], ... ],
 *         'hostings' => [ ['id','label','kind','color','image','icon'], ... ],
 *     ]
 *
 * The map is read at SOLUTION level only (2026-10-07, the user's call): a
 * block per system, an arrow per pair. It used to carry every diagram's
 * resolved chain so a click could unfold the drawings behind a system — too
 * much detail for a picture of the whole ecosystem, so that half of the
 * payload is gone and `edges[].diagrams` (name + slug) is all that remains
 * of the drawings, for the card a click on an arrow opens.
 *
 * `hosting` places a system in the "Por hospedagem" view: see `hostingOf()`.
 *
 * Rules (section 10):
 * - Every candidate edge comes from a real link in `chain.edges` (not from
 *   `diagram_solution.position` adjacency — the chain has been a free
 *   graph since the F3 data-viz, so two solutions "neighboring" in pivot
 *   position may have no link at all between them). iPaaS solutions (e.g.
 *   Digibee) are ordinary chain participants — the orchestrator concept was
 *   removed.
 * - The global map shows **one edge per pair** of solutions (not one per
 *   chain segment): `dedupePairs()` groups every candidate edge between the
 *   same pair (from different diagrams, or revisited within the same
 *   diagram) into a single one, aggregating direction (bidirectional if
 *   flow exists in both directions), status (the "healthiest" in the group)
 *   and protocol (distinct labels, joined). Avoids the tangle of several
 *   overlapping curves between the same pair in the radial hub-and-spoke
 *   layout (`ecosystem-map.js`).
 */
class DiagramGraphService
{
    /** The container for a system with no cloud and no hosting model. */
    public const UNKNOWN_HOSTING = 'unknown';

    /** What a block on the map needs from a solution — and nothing more. */
    private const SOLUTION_COLUMNS = [
        'id', 'name', 'slug', 'category', 'logo_path', 'status', 'criticality',
        'environment', 'cloud', 'contract_status', 'support_type', 'directorate',
    ];

    /**
     * Global map: the whole ecosystem, with optional filters — `status`
     * (default all), `category`, `directorate`.
     *
     * `$wholeCatalog` adds every solution, connected or not: the "Por
     * hospedagem" view is a picture of where EVERYTHING runs, while the links
     * view only has something to say about systems that talk to another one.
     *
     * @param  array<string, string|null>  $filters
     * @return array{nodes: array<int, array<string, mixed>>, edges: array<int, array<string, mixed>>}
     */
    public function globalMap(array $filters = [], bool $wholeCatalog = false): array
    {
        $status = $filters['status'] ?? null;
        $category = $filters['category'] ?? null;
        $directorate = $filters['directorate'] ?? null;

        $diagrams = Diagram::query()
            ->when($status && $status !== 'all', fn (Builder $q) => $q->where('status', $status))
            ->when($category, fn (Builder $q) => $q->whereHas(
                'participants',
                fn (Builder $p) => $p->where('solutions.category', $category)
            ))
            ->when($directorate, fn (Builder $q) => $q->whereHas(
                'participants',
                fn (Builder $p) => $p->where('solutions.directorate', $directorate)
            ))
            ->with($this->graphEagerLoad())
            ->get();

        $extra = $wholeCatalog
            ? Solution::query()->select(self::SOLUTION_COLUMNS)->orderBy('name')->get()
            : collect();

        return $this->build($diagrams, $extra);
    }

    /** Eager loads that avoid N+1 while building nodes/edges. */
    private function graphEagerLoad(): array
    {
        return [
            'participants' => fn ($q) => $q->select(array_map(fn (string $c) => "solutions.{$c}", self::SOLUTION_COLUMNS)),
        ];
    }

    /**
     * Builds the neutral contract from a collection of diagrams, plus any
     * `$extra` solutions to draw even when no diagram names them.
     *
     * @param  Collection<int, Diagram>  $diagrams
     * @param  Collection<int, Solution>  $extra
     * @return array<string, array<int, array<string, mixed>>>
     */
    private function build(Collection $diagrams, Collection $extra): array
    {
        $nodes = [];
        $edges = [];
        $seq = 0;

        foreach ($diagrams as $diagram) {
            foreach ($diagram->participants as $participant) {
                $this->putNode($nodes, $participant);
            }

            $chainNodes = array_values($diagram->chain['nodes'] ?? []);
            $chainEdges = array_values($diagram->chain['edges'] ?? []);

            foreach ($chainEdges as $edge) {
                $fromSid = $this->nodeSolutionId($chainNodes, $edge['from'] ?? null);
                $toSid = $this->nodeSolutionId($chainNodes, $edge['to'] ?? null);

                // An endpoint on a node with no solution (free text, decision or
                // actor) doesn't produce a solution pair — there's no edge to
                // draw on the global map.
                if ($fromSid === null || $toSid === null) {
                    continue;
                }

                // The same solution can occupy two distinct chain nodes
                // (from !== to in the chain, but same solution_id) — on the
                // global map nodes are per-solution, so this would become a
                // meaningless `sol-X -> sol-X` self-loop to draw.
                if ($fromSid === $toSid) {
                    continue;
                }

                $bidirectional = ($edge['arrow'] ?? '->') === '<->';
                $protocol = filled($edge['protocol'] ?? null) ? $edge['protocol'] : $diagram->protocol;

                $edges[] = $this->edge($diagram, "sol-{$fromSid}", "sol-{$toSid}", $bidirectional, $protocol, $seq++);
            }
        }

        foreach ($extra as $solution) {
            $this->putNode($nodes, $solution);
        }

        return [
            'nodes'    => array_values($nodes),
            'edges'    => $this->dedupePairs($edges),
            'hostings' => $this->hostings(array_values($nodes)),
        ];
    }

    /**
     * Solution referenced by one endpoint of a chain edge, or null when that
     * endpoint isn't a solution at all. Goes through `ChainNodeKind` for the
     * same reason as `SyncDiagramFromChain`, `ChainLabeler::nodeLabel()` and
     * `ChainGraph::resolveNode()`: only a `system` node may reference a
     * Solution, so a `solution_id` left behind on a decision/actor node (by an
     * earlier conversion, or by hand-written chain JSON) must never draw a
     * phantom edge between two solutions on the global map.
     *
     * @param  array<int, array<string, mixed>>  $chainNodes
     */
    private function nodeSolutionId(array $chainNodes, mixed $index): ?int
    {
        $node = is_int($index) ? ($chainNodes[$index] ?? null) : null;

        if ($node === null || ! ChainNodeKind::fromNode($node)->referencesSolution()) {
            return null;
        }

        return $node['solution_id'] ?? null;
    }

    /**
     * Groups candidate edges by the unordered pair of solutions, aggregating
     * direction/status/protocol/diagrams across all edges linking the
     * same pair. The first edge seen for a pair defines the group's canonical
     * orientation (`source`/`target`) — every following edge only marks
     * whether the observed flow is in the same direction (`aToB`) or the
     * opposite one (`bToA`); an edge that's already `<->` marks both.
     *
     * @param  array<int, array<string, mixed>>  $edges
     * @return array<int, array<string, mixed>>
     */
    private function dedupePairs(array $edges): array
    {
        $groups = [];
        $order = [];

        foreach ($edges as $edge) {
            $key = $this->pairKey($edge['source'], $edge['target']);

            if (! isset($groups[$key])) {
                $order[] = $key;
                $groups[$key] = [
                    'source'    => $edge['source'],
                    'target'    => $edge['target'],
                    'aToB'      => false,
                    'bToA'      => false,
                    'statuses'  => [],
                    'protocols' => [],
                    'diagrams'  => [],
                ];
            }

            $group = &$groups[$key];
            $forward = $edge['source'] === $group['source'];

            if ($edge['direction'] === Direction::Bidirectional->value) {
                $group['aToB'] = true;
                $group['bToA'] = true;
            } elseif ($forward) {
                $group['aToB'] = true;
            } else {
                $group['bToA'] = true;
            }

            $group['statuses'][] = $edge['status'];
            if (filled($edge['label'])) {
                $group['protocols'][$edge['label']] = true;
            }
            $group['diagrams'][$edge['slug']] = ['slug' => $edge['slug'], 'name' => $edge['diagram_name'], 'url' => $edge['diagram_url']];
            unset($group);
        }

        return array_map(function (string $key) use ($groups) {
            $group = $groups[$key];

            return [
                'id'        => 'pair-' . $key,
                'source'    => $group['source'],
                'target'    => $group['target'],
                'label'     => implode(' · ', array_keys($group['protocols'])),
                'status'    => $this->healthiestStatus($group['statuses']),
                'direction' => ($group['aToB'] && $group['bToA'] ? Direction::Bidirectional : Direction::Unidirectional)->value,
                'diagrams'  => array_values($group['diagrams']),
            ];
        }, $order);
    }

    /** Stable, unordered key for a `sol-{id}` pair — same regardless of which one is source/target. */
    private function pairKey(string $a, string $b): string
    {
        $pair = [$a, $b];
        sort($pair);

        return implode('|', $pair);
    }

    /** @param  array<int, string>  $statuses */
    private function healthiestStatus(array $statuses): string
    {
        foreach (DiagramStatus::cases() as $status) {
            if (in_array($status->value, $statuses, true)) {
                return $status->value;
            }
        }

        return $statuses[0];
    }

    /**
     * Besides the essentials for drawing (name/logo), loads the same 8
     * attributes shown in `Solutions\DetailHeader` — the map's attribute
     * popover (`ecosystem-map.js`) displays them without needing an AJAX
     * round-trip per click. `url` avoids rebuilding the route on the client.
     * `categoryFamily` is the color family `CategoryPalette` already assigns
     * this category everywhere else in the app — the canvas resolves it to a
     * hex by reading the `--color-cat-*` token, so the palette stays defined
     * in one place (`@theme`) instead of being restated as literals in JS.
     *
     * The map no longer carries a position per solution: the layout is
     * computed (rings/force), so a stored `x`/`y` had nothing left to be
     * saved against.
     *
     * @param  array<string, array<string, mixed>>  $nodes
     */
    private function putNode(array &$nodes, Solution $solution): void
    {
        $id = "sol-{$solution->id}";

        if (isset($nodes[$id])) {
            return;
        }

        $nodes[$id] = $this->solutionNode($solution);
    }

    /**
     * One solution as the map's renderer expects it — the same block in both
     * views, so a system never changes shape when the view does.
     *
     * @return array<string, mixed>
     */
    private function solutionNode(Solution $solution): array
    {
        return [
            'id'               => "sol-{$solution->id}",
            'label'            => $solution->name,
            'slug'             => $solution->slug,
            'category'         => $solution->category,
            'logo'             => $solution->logo_path ? Storage::disk('public')->url($solution->logo_path) : null,
            'url'              => route('solutions.show', $solution),
            'categoryLabel'    => $solution->category_label,
            'statusLabel'      => $solution->status_label,
            'criticalityLabel' => $solution->criticality_label,
            'environmentLabel' => $solution->environment_label,
            'cloudLabel'       => $solution->cloud_label,
            'contractLabel'    => $solution->contract_status_label,
            'supportLabel'     => $solution->support_type_label,
            'directorate'      => $solution->directorate,
            'categoryFamily'   => CategoryPalette::family($solution->category),
            'hosting'          => $this->hostingOf($solution),
        ];
    }

    /**
     * Which container a system sits in on the "Por hospedagem" view.
     *
     * The CLOUD wins when there is one — "SaaS on AWS" runs on AWS, and that is
     * the fact the picture is about — and the hosting model (SaaS, On-Premises…)
     * answers for everything else. A system with neither lands in the "Não
     * informado" container rather than nowhere, so the view still accounts for
     * the whole catalog and shows what is left to fill in.
     */
    public function hostingOf(Solution $solution): string
    {
        return match (true) {
            filled($solution->cloud)       => "cloud:{$solution->cloud}",
            filled($solution->environment) => "environment:{$solution->environment}",
            default                        => self::UNKNOWN_HOSTING,
        };
    }

    /**
     * The containers the given nodes fall into, with the look the attribute
     * screen gives them (colour, picture, icon). Clouds first, then hosting
     * models, then "Não informado" — the order they are laid out in.
     *
     * @param  array<int, array<string, mixed>>  $nodes
     * @return array<int, array<string, mixed>>
     */
    private function hostings(array $nodes): array
    {
        $used = array_unique(array_column($nodes, 'hosting'));
        $containers = [];

        foreach (['cloud', 'environment'] as $group) {
            foreach (AttributeOption::options($group) as $option) {
                $id = "{$group}:{$option->value}";
                if (! in_array($id, $used, true)) {
                    continue;
                }
                $containers[] = [
                    'id'    => $id,
                    'label' => $option->label,
                    'kind'  => $group,
                    'color' => $option->color ?? AttributeOption::DEFAULT_COLOR,
                    'image' => $option->imageUrl(),
                    'icon'  => $option->icon,
                ];
                $used = array_diff($used, [$id]);
            }
        }

        // Whatever is left is unknown, or a value whose option was deleted —
        // both are "we do not know where this runs".
        if ($used !== []) {
            $containers[] = [
                'id'    => self::UNKNOWN_HOSTING, 'label' => 'Não informado', 'kind' => null,
                'color' => AttributeOption::DEFAULT_COLOR, 'image' => null, 'icon' => null,
            ];
        }

        return $containers;
    }

    /**
     * Candidate edge (per segment) — input to `dedupePairs()`, never returned directly.
     * `$protocol` is the raw stored value (free text or an `App\Enums\Protocol`
     * case's value) — resolved to its human label the same way
     * `ChainGraph::resolveProtocol()` does, so a free-text protocol still
     * shows up on the global map instead of being silently dropped.
     *
     * @return array<string, mixed>
     */
    private function edge(Diagram $diagram, string $source, string $target, bool $bidirectional, ?string $protocol, int $seq): array
    {
        return [
            'id'           => "int-{$diagram->id}-{$seq}",
            'source'       => $source,
            'target'       => $target,
            'label'        => filled($protocol) ? (Protocol::tryFrom($protocol)?->label() ?? $protocol) : '',
            'status'       => $diagram->status->value,
            'direction'    => ($bidirectional ? Direction::Bidirectional : Direction::Unidirectional)->value,
            'slug'         => $diagram->slug,
            'diagram_name' => $diagram->name,
            'diagram_url'  => route('diagrams.show', $diagram),
        ];
    }
}
