<?php

namespace App\Services;

use App\Enums\ChainNodeKind;
use App\Enums\Direction;
use App\Enums\IntegrationStatus;
use App\Enums\Protocol;
use App\Models\Integration;
use App\Models\Solution;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Storage;

/**
 * Resolves the integration graph into the NEUTRAL (renderer-agnostic)
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
 *             'mapPosition','positionUrl'], ... ],
 *         'edges' => [ ['id','source','target','label','status','direction','integrations'], ... ],
 *     ]
 *
 * Rules (section 10):
 * - Every candidate edge comes from a real link in `chain.edges` (not from
 *   `integration_solution.position` adjacency — the chain has been a free
 *   graph since the F3 data-viz, so two solutions "neighboring" in pivot
 *   position may have no link at all between them). iPaaS solutions (e.g.
 *   Digibee) are ordinary chain participants — the orchestrator concept was
 *   removed.
 * - The global map shows **one edge per pair** of solutions (not one per
 *   chain segment): `dedupePairs()` groups every candidate edge between the
 *   same pair (from different integrations, or revisited within the same
 *   integration) into a single one, aggregating direction (bidirectional if
 *   flow exists in both directions), status (the "healthiest" in the group)
 *   and protocol (distinct labels, joined). Avoids the tangle of several
 *   overlapping curves between the same pair in the radial hub-and-spoke
 *   layout (`ecosystem-map.js`).
 */
class IntegrationGraphService
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

        $integrations = Integration::query()
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

        return $this->build($integrations);
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
                'solutions.map_position',
            ),
        ];
    }

    /**
     * Builds the neutral contract from a collection of integrations.
     *
     * @param  Collection<int, Integration>  $integrations
     * @return array{nodes: array<int, array<string, mixed>>, edges: array<int, array<string, mixed>>}
     */
    private function build(Collection $integrations): array
    {
        $nodes = [];
        $edges = [];
        $seq = 0;

        foreach ($integrations as $integration) {
            foreach ($integration->participants as $participant) {
                $this->putNode($nodes, $participant);
            }

            $chainNodes = array_values($integration->chain['nodes'] ?? []);
            $chainEdges = array_values($integration->chain['edges'] ?? []);

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
                $protocol = filled($edge['protocol'] ?? null)
                    ? Protocol::tryFrom($edge['protocol'])
                    : $integration->protocol;

                $edges[] = $this->edge($integration, "sol-{$fromSid}", "sol-{$toSid}", $bidirectional, $protocol, $seq++);
            }
        }

        return [
            'nodes' => array_values($nodes),
            'edges' => $this->dedupePairs($edges),
        ];
    }

    /**
     * Solution referenced by one endpoint of a chain edge, or null when that
     * endpoint isn't a solution at all. Goes through `ChainNodeKind` for the
     * same reason as `SyncIntegrationFromChain`, `ChainLabeler::nodeLabel()` and
     * `IntegrationsMap::resolveNode()`: only a `system` node may reference a
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
     * direction/status/protocol/integrations across all edges linking the
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
                    'source'       => $edge['source'],
                    'target'       => $edge['target'],
                    'aToB'         => false,
                    'bToA'         => false,
                    'statuses'     => [],
                    'protocols'    => [],
                    'integrations' => [],
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
            $group['integrations'][$edge['slug']] = ['slug' => $edge['slug'], 'name' => $edge['integration_name']];
            unset($group);
        }

        return array_map(function (string $key) use ($groups) {
            $group = $groups[$key];

            return [
                'id'           => 'pair-' . $key,
                'source'       => $group['source'],
                'target'       => $group['target'],
                'label'        => implode(' · ', array_keys($group['protocols'])),
                'status'       => $this->healthiestStatus($group['statuses']),
                'direction'    => ($group['aToB'] && $group['bToA'] ? Direction::Bidirectional : Direction::Unidirectional)->value,
                'integrations' => array_values($group['integrations']),
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
        foreach (IntegrationStatus::cases() as $status) {
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
     * round-trip per click. `url` avoids rebuilding the route on the client,
     * same as `positionUrl` (the hub drag-and-drop's auto-save endpoint).
     * `mapPosition` is the last dragged-and-saved position (null until the
     * first drag) — `ecosystem-map.js::layout()` uses it instead of the
     * packed grid for this hub.
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
            'mapPosition'      => $solution->map_position,
            'positionUrl'      => route('solutions.map.position.update', $solution),
        ];
    }

    /**
     * Candidate edge (per segment) — input to `dedupePairs()`, never returned directly.
     *
     * @return array<string, mixed>
     */
    private function edge(Integration $integration, string $source, string $target, bool $bidirectional, ?Protocol $protocol, int $seq): array
    {
        return [
            'id'               => "int-{$integration->id}-{$seq}",
            'source'           => $source,
            'target'           => $target,
            'label'            => $protocol?->label() ?? '',
            'status'           => $integration->status->value,
            'direction'        => ($bidirectional ? Direction::Bidirectional : Direction::Unidirectional)->value,
            'slug'             => $integration->slug,
            'integration_name' => $integration->name,
        ];
    }
}
