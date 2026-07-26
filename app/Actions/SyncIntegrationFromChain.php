<?php

namespace App\Actions;

use App\Enums\ChainNodeKind;
use App\Enums\Direction;
use App\Models\Integration;
use Illuminate\Support\Facades\DB;

/**
 * Rebuilds the Integration's structured fields from the `chain` — the free
 * graph authored entirely by the F3 data-viz (`integration-viz.js`: root
 * node on creation, blocks/edges/retargeting afterward) —, the source of
 * truth for topology since decoupling from `diagram` (which went back to
 * being a free-form drawing, with no side effect on participants).
 *
 * `chain = {nodes: [{solution_id, label, kind}], edges: [{from, to, arrow, protocol}]}`
 * — `from`/`to` are indices into `nodes`, no longer consecutive positions.
 * Nodes with no solution (free text, and every decision/actor node — see
 * `ChainNodeKind`) count toward neighbors' in/out degree but don't become
 * participants (the pivot references solutions).
 */
class SyncIntegrationFromChain
{
    public function handle(Integration $integration): void
    {
        $chain = $integration->chain ?? [];
        $nodes = array_values($chain['nodes'] ?? []);
        $edges = array_values($chain['edges'] ?? []);

        // In/out degree per node index, following each edge's arrow direction.
        $in = array_fill(0, count($nodes), 0);
        $out = array_fill(0, count($nodes), 0);
        $sawForward = false;
        $sawBackward = false;
        foreach ($edges as $edge) {
            $from = $edge['from'] ?? null;
            $to = $edge['to'] ?? null;
            if (! isset($nodes[$from]) || ! isset($nodes[$to])) {
                continue;
            }
            $arrow = $edge['arrow'] ?? '->';
            $sawForward = $sawForward || $arrow !== '<-';
            $sawBackward = $sawBackward || $arrow !== '->';
            if ($arrow !== '<-') { // '->' and '<->'
                $out[$from]++;
                $in[$to]++;
            }
            if ($arrow !== '->') { // '<-' and '<->'
                $out[$to]++;
                $in[$from]++;
            }
        }

        // Only a system node can reference a Solution (`ChainNodeKind`), so a
        // decision/actor node never becomes a participant — not even if some
        // hand-written chain left a `solution_id` on it.
        $solutionNodes = collect($nodes)
            ->map(fn ($node, $i) => [
                'index'       => $i,
                'solution_id' => ChainNodeKind::fromNode($node)->referencesSolution() ? ($node['solution_id'] ?? null) : null,
            ])
            ->filter(fn ($node) => ! empty($node['solution_id']))
            ->values();

        if ($solutionNodes->isEmpty()) {
            DB::transaction(function () use ($integration) {
                $integration->participants()->detach();
                $integration->update([
                    'source_solution_id' => null,
                    'target_solution_id' => null,
                ]);
            });

            return;
        }

        // Aggregated per distinct solution (a solution can appear in more than one node).
        $inBySolution = [];
        $outBySolution = [];
        $positions = [];
        foreach ($solutionNodes as $node) {
            $sid = $node['solution_id'];
            $inBySolution[$sid] = ($inBySolution[$sid] ?? 0) + $in[$node['index']];
            $outBySolution[$sid] = ($outBySolution[$sid] ?? 0) + $out[$node['index']];
            $positions[$sid] = min($positions[$sid] ?? PHP_INT_MAX, $node['index']);
        }

        // The flow's endpoints can only be solutions that are IN the flow. An
        // isolated block — which is how EVERY block is born now, see
        // `addNode()` — has in === 0 *and* out === 0, so without this filter it
        // would win both picks below (`first()` on in === 0, `last()` on
        // out === 0, being the last node) and silently become the integration's
        // source/target despite having no edge at all. When nothing is
        // connected yet, there's no flow to describe: fall back to the whole
        // set, which keeps the "single node" case (`store()`) pointing at itself.
        $connected = $solutionNodes->filter(
            fn ($n) => $inBySolution[$n['solution_id']] + $outBySolution[$n['solution_id']] > 0
        );
        $endpoints = $connected->isNotEmpty() ? $connected : $solutionNodes;

        $sourceId = ($endpoints->first(fn ($n) => $inBySolution[$n['solution_id']] === 0) ?? $endpoints->first())['solution_id'];
        $targetId = ($endpoints->last(fn ($n) => $outBySolution[$n['solution_id']] === 0) ?? $endpoints->last())['solution_id'];

        $bidirectional = $sawForward && $sawBackward;

        // Protocol is defined per edge (chain.edges[i].protocol). The
        // `integrations.protocol` scalar holds a representative value (the
        // first edge with a protocol, in storage order) only for summary
        // display — the per-edge truth lives in the chain.
        $protocol = collect($edges)->pluck('protocol')->first(fn ($p) => filled($p));

        $pivotData = collect($positions)->map(fn ($position) => ['position' => $position])->all();

        DB::transaction(function () use ($integration, $pivotData, $sourceId, $targetId, $bidirectional, $protocol) {
            $integration->participants()->detach();
            $integration->participants()->attach($pivotData);

            $integration->update([
                'source_solution_id' => $sourceId,
                'target_solution_id' => $targetId,
                'direction'          => ($bidirectional ? Direction::Bidirectional : Direction::Unidirectional)->value,
                'protocol'           => $protocol,
            ]);
        });
    }
}
