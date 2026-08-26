<?php

namespace App\Support;

use App\Enums\ChainNodeKind;
use App\Models\Solution;
use Illuminate\Support\Collection;

/**
 * Human-readable label of a diagram chain (`{nodes, edges}` — each edge
 * is `{from, to, arrow, protocol}` by node index, no longer positional).
 * Shared between the controller (derived name when creation doesn't supply
 * one) and the solution detail's diagram list (`Solutions\Diagrams`),
 * to avoid duplicating the "A -> B -> C" text assembly.
 */
class ChainLabeler
{
    /**
     * Solutions referenced by the given chains — a single query, to label
     * nodes and (in F3) link to the solution detail, without N+1. Also
     * brings `environment`/`cloud`/`logo_path` — used by
     * `Solutions\Diagrams` to highlight environment/cloud and the
     * logo on top of each block in the data-viz (`chain-viz.js`).
     *
     * @param  Collection<int, array|null>  $chains
     * @return Collection<int, Solution>
     */
    public function resolveSolutions(Collection $chains): Collection
    {
        $ids = $chains->filter()
            ->flatMap(fn (array $chain) => collect($chain['nodes'] ?? [])->pluck('solution_id'))
            ->filter()
            ->unique();

        return $ids->isEmpty()
            ? collect()
            : Solution::whereIn('id', $ids)->get(['id', 'name', 'slug', 'environment', 'cloud', 'logo_path'])->keyBy('id');
    }

    /**
     * Full chain text — becomes the diagram's name when the field is
     * left blank. When the chain is a simple line (`isLinear()`), produces
     * "A -> B -> C" by walking the nodes in order. A chain retargeted into a
     * free graph in the data-viz (F3) no longer has that single order —
     * lists each edge separately ("A -> B, B -> C, A -> C"), followed (if
     * any) by blocks with no edge at all — "free" in the graph, but that
     * still need to appear in the summary.
     */
    public function label(array $chain, Collection $solutions): string
    {
        $nodeLabels = collect($chain['nodes'] ?? [])->map(fn ($node) => $this->nodeLabel($node, $solutions))->values();
        $edges = collect($chain['edges'] ?? [])->values();

        if ($nodeLabels->isEmpty()) {
            return '';
        }

        if ($this->isLinear($chain)) {
            return $nodeLabels->reduce(
                fn (?string $carry, string $label, int $i) => $carry === null ? $label : "{$carry} {$edges[$i - 1]['arrow']} {$label}",
            ) ?? '';
        }

        if ($edges->isEmpty()) {
            return $nodeLabels->implode(', ');
        }

        $connected = $edges->flatMap(fn ($edge) => [$edge['from'] ?? null, $edge['to'] ?? null])->filter(fn ($i) => $i !== null)->unique();
        $isolated = $nodeLabels->keys()->diff($connected)->map(fn ($i) => $nodeLabels[$i]);

        return $edges->map(fn ($edge) => sprintf(
            '%s %s %s',
            $nodeLabels[$edge['from'] ?? null] ?? '?',
            $edge['arrow'] ?? '->',
            $nodeLabels[$edge['to'] ?? null] ?? '?',
        ))->concat($isolated)->implode(', ');
    }

    /**
     * A chain is "linear" when `edges[i]` always connects `nodes[i]` to
     * `nodes[i+1]`, in order — used only by `label()` above to choose the
     * text summary format ("A -> B -> C" vs. a list of separate edges).
     * Every diagram is born linear (just the root node); as soon as the
     * data-viz (F3) retargets an edge to a node outside that sequence, the
     * chain stops being linear.
     */
    public function isLinear(array $chain): bool
    {
        $nodeCount = count($chain['nodes'] ?? []);
        $edges = array_values($chain['edges'] ?? []);

        if (count($edges) !== max(0, $nodeCount - 1)) {
            return false;
        }

        foreach ($edges as $i => $edge) {
            if (($edge['from'] ?? null) !== $i || ($edge['to'] ?? null) !== $i + 1) {
                return false;
            }
        }

        return true;
    }

    /**
     * A node's text: the referenced Solution's name, or the free text. Only a
     * system node can reference a Solution (`ChainNodeKind`), so on a
     * decision/actor node the free text always wins — a `solution_id` left
     * behind by an earlier conversion (or by hand-written chain JSON) never
     * shadows it.
     *
     * @param  array{solution_id?: int|null, label?: string|null, kind?: string|null}  $node
     */
    public function nodeLabel(array $node, Collection $solutions): string
    {
        if (! ChainNodeKind::fromNode($node)->referencesSolution()) {
            return $node['label'] ?? '?';
        }

        return $solutions[$node['solution_id'] ?? null]?->name ?? $node['label'] ?? '?';
    }
}
