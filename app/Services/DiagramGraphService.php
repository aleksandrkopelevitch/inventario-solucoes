<?php

namespace App\Services;

use App\Enums\ChainNodeKind;
use App\Enums\DiagramStatus;
use App\Enums\Direction;
use App\Enums\Protocol;
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
 *             'categoryFamily'], ... ],
 *         'edges' => [ ['id','source','target','label','status','direction','diagrams'], ... ],
 *         'diagrams' => [ ['id','slug','label','url','status','statusLabel',
 *             'criticalityLabel','syncModeLabel','protocolLabel','solutions',
 *             'chain' => ['nodes','edges']], ... ],
 *     ]
 *
 * The three levels of the map are all in there, in one response: the macro
 * graph is `nodes` + `edges`, expanding a solution or a pair hangs the
 * `diagrams` that name it (`solutions`, and `edges[].diagrams`), and opening
 * one of those draws its `chain`. It is one fetch because the whole thing is
 * one reading of the same diagrams — splitting it would re-query the same
 * rows per click and make the search box unable to see a drawing nobody had
 * expanded yet. Measured at 21 KB for the ten seeded diagrams; if the
 * portfolio ever reaches a few hundred, `chain` is the half to move behind a
 * per-diagram endpoint (it is ~80% of the bytes), not the catalog above it.
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
    /**
     * Global map: the whole ecosystem, with optional query-string filters.
     * Accepted filters: `status` (default all), `category`, `directorate`.
     *
     * @param  array<string, string|null>  $filters
     * @return array{nodes: array<int, array<string, mixed>>, edges: array<int, array<string, mixed>>}
     */
    public function globalMap(array $filters = []): array
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

        return $this->build($diagrams);
    }

    /** Eager loads that avoid N+1 while building nodes/edges. */
    private function graphEagerLoad(): array
    {
        return [
            'participants' => fn ($q) => $q->select(
                'solutions.id',
                'solutions.name',
                'solutions.slug',
                'solutions.category',
                'solutions.logo_path',
                'solutions.status',
                'solutions.criticality',
                'solutions.environment',
                'solutions.cloud',
                'solutions.contract_status',
                'solutions.support_type',
                'solutions.directorate',
            ),
        ];
    }

    /**
     * Builds the neutral contract from a collection of diagrams.
     *
     * @param  Collection<int, Diagram>  $diagrams
     * @return array{nodes: array<int, array<string, mixed>>, edges: array<int, array<string, mixed>>}
     */
    private function build(Collection $diagrams): array
    {
        $nodes = [];
        $edges = [];
        $drawings = [];
        $seq = 0;

        // One query for every solution referenced by ANY chain node, so the
        // drill-down's third level resolves its labels and logos without an
        // N+1 — and without depending on the participants pivot, which only
        // ever holds the `system` nodes.
        $chainSolutions = (new ChainLabeler)->resolveSolutions($diagrams->map->chainData());

        foreach ($diagrams as $diagram) {
            foreach ($diagram->participants as $participant) {
                $this->putNode($nodes, $participant);
            }

            $drawings[] = $this->drawing($diagram, $chainSolutions);

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

        return [
            'nodes'    => array_values($nodes),
            'edges'    => $this->dedupePairs($edges),
            'diagrams' => $drawings,
        ];
    }

    /**
     * One diagram, resolved for the map's two deeper levels: the card the
     * macro graph expands into, and the drawing that card opens.
     *
     * The chain is resolved HERE rather than through `ChainGraph::resolveNode()`
     * even though both answer the same two questions (`ChainNodeKind::fromNode()`
     * for the kind, `ChainLabeler::nodeLabel()` for the text). What that one
     * adds is what the F3 canvas needs and this one cannot use: a rendered
     * heroicon per node — the map draws shapes on a canvas, so an SVG string
     * per node would be a few hundred bytes each, on every node of every
     * diagram, for markup nothing here mounts — plus environment/cloud badges
     * and an authenticated media URL. Both call sites go through the same two
     * decisions, which is the part that must never diverge.
     *
     * @param  Collection<int, Solution>  $solutions
     * @return array<string, mixed>
     */
    private function drawing(Diagram $diagram, Collection $solutions): array
    {
        $chain = $diagram->chainData() ?? [];
        $chainNodes = array_values($chain['nodes'] ?? []);
        $labeler = new ChainLabeler;

        return [
            'id'               => "diag-{$diagram->slug}",
            'slug'             => $diagram->slug,
            'label'            => $diagram->name,
            'url'              => route('diagrams.show', $diagram),
            'status'           => $diagram->status->value,
            'statusLabel'      => $diagram->status->label(),
            'criticalityLabel' => $diagram->criticality_label,
            'syncModeLabel'    => $diagram->sync_mode?->label(),
            'protocolLabel'    => filled($diagram->protocol)
                ? (Protocol::tryFrom($diagram->protocol)?->label() ?? $diagram->protocol)
                : null,
            // The macro nodes this diagram hangs off — every solution it
            // touches, so expanding either SAP or the SAP-Digibee pair finds
            // it. Read off the chain and not off the pivot for the reason
            // above: one list, one rule (`referencesSolution()`).
            'solutions' => collect($chainNodes)
                ->map(fn (array $node) => ChainNodeKind::fromNode($node)->referencesSolution()
                    ? ($node['solution_id'] ?? null)
                    : null)
                ->filter()
                ->unique()
                ->map(fn (int $id) => "sol-{$id}")
                ->values()
                ->all(),
            'chain' => [
                'nodes' => collect($chainNodes)->map(function (array $node) use ($labeler, $solutions) {
                    $kind = ChainNodeKind::fromNode($node);
                    $solution = $kind->referencesSolution() ? ($solutions[$node['solution_id'] ?? null] ?? null) : null;

                    return [
                        'label'      => $labeler->nodeLabel($node, $solutions),
                        'kind'       => $kind->value,
                        'solutionId' => $solution ? "sol-{$solution->id}" : null,
                        'logo'       => $solution?->logo_path ? Storage::disk('public')->url($solution->logo_path) : null,
                        'url'        => $solution ? route('solutions.show', $solution) : null,
                    ];
                })->values()->all(),
                'edges' => collect($chain['edges'] ?? [])->map(fn (array $edge) => [
                    'from'     => $edge['from'] ?? 0,
                    'to'       => $edge['to'] ?? 0,
                    'arrow'    => $edge['arrow'] ?? '->',
                    'protocol' => filled($edge['protocol'] ?? null)
                        ? (Protocol::tryFrom($edge['protocol'])?->label() ?? $edge['protocol'])
                        : null,
                ])->values()->all(),
            ],
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
            $group['diagrams'][$edge['slug']] = ['slug' => $edge['slug'], 'name' => $edge['diagram_name']];
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

        $nodes[$id] = [
            'id'               => $id,
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
        ];
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
        ];
    }
}
