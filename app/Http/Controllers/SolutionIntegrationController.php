<?php

namespace App\Http\Controllers;

use App\Actions\SyncIntegrationFromChain;
use App\Enums\Direction;
use App\Http\Requests\AddIntegrationChainEdgeRequest;
use App\Http\Requests\AddIntegrationChainNodeRequest;
use App\Http\Requests\RemoveIntegrationChainEdgeRequest;
use App\Http\Requests\RetargetIntegrationChainEdgeRequest;
use App\Http\Requests\SaveIntegrationLayoutRequest;
use App\Http\Requests\StoreIntegrationRequest;
use App\Http\Requests\UpdateIntegrationChainNodeRequest;
use App\Http\Requests\UpdateIntegrationChainProtocolRequest;
use App\Http\Requests\UpdateIntegrationMetaRequest;
use App\Models\Integration;
use App\Models\Solution;
use App\Support\ChainLabeler;
use App\View\Components\Solutions\IntegrationsMap;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Str;

/**
 * Integrations for a solution's detail page (F3). The F3 data-viz
 * (`integration-viz.js`) is what authors the topology (chain: nodes/edges,
 * via `updateNode()`/`updateProtocol()`/`addNode()`/`retargetEdge()` below)
 * and the visual layout (`saveLayout()`) — this controller only covers what
 * the data-viz doesn't yet do on its own: creating a brand-new Integration
 * (`store()`) and renaming/changing the status of an existing one
 * (`update()`), neither of which touches the chain. `SyncIntegrationFromChain`
 * remains the only place that derives participants/source/target/direction
 * from the chain.
 */
class SolutionIntegrationController extends Controller
{
    public function __construct(
        private readonly SyncIntegrationFromChain $sync,
        private readonly ChainLabeler $labeler,
    ) {}

    /**
     * Creates a brand-new Integration with the context solution as the root
     * node — chain = {nodes: [root], edges: []}, ready for the data-viz to
     * freely add blocks (`addNode()`) and rewire (`retargetEdge()`). Name is
     * optional (falls back to the root solution's name); initial status is
     * "planned", adjustable afterwards via `update()`.
     */
    public function store(StoreIntegrationRequest $request, Solution $solution): JsonResponse
    {
        $data = $request->validated();
        $chain = [
            'nodes' => [['solution_id' => $solution->id, 'label' => null]],
            'edges' => [],
        ];

        $name = trim($data['name'] ?? '') ?: $solution->name;

        $integration = Integration::create([
            'name'        => $name,
            'slug'        => $this->uniqueSlug($name),
            'status'      => 'planned',
            'criticality' => 'medium',
            'direction'   => Direction::Unidirectional->value, // re-derived from the chain right below
            'chain'       => $chain,
        ]);

        $this->sync->handle($integration);

        return response()->json([
            'type'           => 'success',
            'message'        => 'Integração criada.',
            'updatableSlots' => [IntegrationsMap::slot($solution)],
            // Selects the newly created integration in the list, opening the
            // data-viz already ready to receive the first block.
            'js' => 'document.querySelector(\'[data-ak-integration-select="' . $integration->slug . '"]\')?.click()',
        ]);
    }

    /** Renames / changes the status of an existing integration — doesn't touch the chain. */
    public function update(UpdateIntegrationMetaRequest $request, Solution $solution, Integration $integration): JsonResponse
    {
        $integration->update($request->validated());

        return response()->json([
            'type'           => 'success',
            'message'        => 'Integração atualizada.',
            'updatableSlots' => [IntegrationsMap::slot($solution)],
        ]);
    }

    /**
     * Saves the F3 graph's visual layout (block positions, edge endpoint
     * anchors, and each block's markdown comment, all keyed by the node's
     * index in the chain). Presentation only — the `chain` remains the
     * source of topology, so we do NOT call SyncIntegrationFromChain here
     * nor touch participants/source/target/direction.
     */
    public function saveLayout(SaveIntegrationLayoutRequest $request, Solution $solution, Integration $integration): JsonResponse
    {
        $integration->update([
            'viz_layout' => $request->safe()->only(['nodes', 'edges', 'comments']),
        ]);

        return response()->json([
            'type'    => 'success',
            'message' => 'Layout salvo.',
        ]);
    }

    /**
     * Updates the title of a single node (F3 data-viz block) — chosen from a
     * registered Solution (pulls name/logo/attributes) or free text. Still
     * edits the `chain` (source of truth for topology), so
     * SyncIntegrationFromChain runs again: swapping a node's Solution can
     * change participants/source/target/direction. The root node (index 0)
     * is fixed — it never reaches here (blocked client-side, enforced by the
     * 404 below).
     */
    public function updateNode(UpdateIntegrationChainNodeRequest $request, Solution $solution, Integration $integration, int $node): JsonResponse
    {
        $chain = $integration->chain;
        abort_if(! $chain || $node <= 0 || ! isset($chain['nodes'][$node]), 404);

        $chain['nodes'][$node] = $request->validated();
        $integration->update(['chain' => $chain]);
        $this->sync->handle($integration);

        $integration = $integration->fresh();
        $solutions = $this->labeler->resolveSolutions(collect([$integration->chain]));
        $comment = $integration->viz_layout['comments'][$node] ?? null;

        return response()->json([
            'type'    => 'success',
            'message' => 'Título do nó atualizado.',
            'node'    => IntegrationsMap::resolveNode($integration->chain['nodes'][$node], $solutions, $comment),
            'summary' => $this->labeler->label($integration->chain, $solutions),
        ]);
    }

    /**
     * Updates the protocol and/or direction (`arrow`) of a single edge —
     * edited in place from the editor anchored to the protocol pill in the
     * F3 data-viz. Unlike the node, there's no protected edge (no "root"
     * among edges); `$edge` is the index in `chain.edges`. Still edits the
     * `chain`, so SyncIntegrationFromChain runs again: the
     * `integrations.protocol` scalar (1st edge with a protocol, summary
     * only) and `direction` (bidirectional depends on `arrow`) are also
     * derived from here.
     */
    public function updateProtocol(UpdateIntegrationChainProtocolRequest $request, Solution $solution, Integration $integration, int $edge): JsonResponse
    {
        $chain = $integration->chain;
        $edges = $chain['edges'] ?? [];
        abort_if(! $chain || $edge < 0 || ! isset($edges[$edge]), 404);

        $data = $request->validated();
        $edges[$edge]['protocol'] = $data['protocol'];
        if (array_key_exists('arrow', $data)) {
            $edges[$edge]['arrow'] = $data['arrow'];
        }
        $chain['edges'] = $edges;

        $integration->update(['chain' => $chain]);
        $this->sync->handle($integration);

        return response()->json([
            'type'     => 'success',
            'message'  => 'Ligação atualizada.',
            'protocol' => IntegrationsMap::resolveProtocol($edges[$edge]['protocol']),
            'arrow'    => $edges[$edge]['arrow'] ?? '->',
        ]);
    }

    /**
     * Appends a new block to the end of the chain (the F3 data-viz's "Add
     * block" panel) — chosen from a registered Solution or free text. When
     * the panel supplies an arrow (`data['arrow']` present), links the new
     * block to the node currently at the end via a new edge (arrow/protocol
     * from the panel); when it doesn't ("No connection"), the block is born
     * isolated, with no edge at all. Either way this is just the starting
     * point: the user can then drag any edge's endpoint to this block
     * (`retargetEdge()`) or use "connect mode" to create a new edge to it
     * (`addEdge()`), rewiring the chain into a free graph. Still edits the
     * `chain` (source of truth for topology), so SyncIntegrationFromChain
     * runs again.
     */
    public function addNode(AddIntegrationChainNodeRequest $request, Solution $solution, Integration $integration): JsonResponse
    {
        $chain = $integration->chain;
        abort_if(! $chain, 404);

        $data = $request->validated();
        $chain['nodes'][] = ['solution_id' => $data['solution_id'], 'label' => $data['label']];
        $newIndex = count($chain['nodes']) - 1;

        $edge = null;
        if ($data['arrow']) {
            $edge = [
                'from'     => max(0, $newIndex - 1),
                'to'       => $newIndex,
                'arrow'    => $data['arrow'],
                'protocol' => $data['protocol'],
            ];
            $chain['edges'][] = $edge;
        }

        $integration->update(['chain' => $chain]);
        $this->sync->handle($integration);

        $integration = $integration->fresh();
        $solutions = $this->labeler->resolveSolutions(collect([$integration->chain]));

        return response()->json([
            'type'     => 'success',
            'message'  => 'Bloco adicionado.',
            'node'     => IntegrationsMap::resolveNode($integration->chain['nodes'][$newIndex], $solutions, null),
            'from'     => $edge['from'] ?? null,
            'arrow'    => $edge['arrow'] ?? null,
            'protocol' => $edge ? IntegrationsMap::resolveProtocol($edge['protocol']) : null,
            'summary'  => $this->labeler->label($integration->chain, $solutions),
        ]);
    }

    /**
     * Rewires one endpoint of an existing edge (`from` or `to`) to any other
     * block — dragging the arrow's handle in the F3 data-viz to another node
     * (`integration-viz.js::retargetEdge()`). This is what turns the chain
     * into a free graph instead of a straight line: once rewired outside the
     * 0→1→2→… sequence, `ChainLabeler::isLinear()` starts rejecting that
     * chain (used only to choose the textual summary's format, see
     * `ChainLabeler::label()`). Still edits the `chain`, so
     * SyncIntegrationFromChain runs again.
     */
    public function retargetEdge(RetargetIntegrationChainEdgeRequest $request, Solution $solution, Integration $integration, int $edge): JsonResponse
    {
        $chain = $integration->chain;
        $edges = $chain['edges'] ?? [];
        abort_if(! $chain || $edge < 0 || ! isset($edges[$edge]), 404);

        $data = $request->validated();
        $edges[$edge][$data['end']] = $data['node'];
        $chain['edges'] = $edges;

        $integration->update(['chain' => $chain]);
        $this->sync->handle($integration);

        $integration = $integration->fresh();
        $solutions = $this->labeler->resolveSolutions(collect([$integration->chain]));

        return response()->json([
            'type'    => 'success',
            'message' => 'Ligação atualizada.',
            'from'    => $edges[$edge]['from'],
            'to'      => $edges[$edge]['to'],
            'summary' => $this->labeler->label($integration->chain, $solutions),
        ]);
    }

    /**
     * Creates a new edge between two blocks that already exist in the chain
     * — the F3 data-viz's "connect mode" (block toolbar button, then click
     * on another block). Unlike `addNode()`, it doesn't add any node; unlike
     * `retargetEdge()`, it doesn't move an existing edge — it's a brand-new
     * edge from scratch, which lets you connect any pair of already-drawn
     * blocks, even if they weren't part of the same "line" of the chain.
     * Still edits the `chain`, so SyncIntegrationFromChain runs again.
     */
    public function addEdge(AddIntegrationChainEdgeRequest $request, Solution $solution, Integration $integration): JsonResponse
    {
        $chain = $integration->chain;
        abort_if(! $chain, 404);

        $data = $request->validated();
        $chain['edges'][] = [
            'from'     => $data['from'],
            'to'       => $data['to'],
            'arrow'    => $data['arrow'],
            'protocol' => $data['protocol'],
        ];

        $integration->update(['chain' => $chain]);
        $this->sync->handle($integration);

        $integration = $integration->fresh();
        $solutions = $this->labeler->resolveSolutions(collect([$integration->chain]));

        return response()->json([
            'type'     => 'success',
            'message'  => 'Ligação criada.',
            'from'     => $data['from'],
            'to'       => $data['to'],
            'arrow'    => $data['arrow'],
            'protocol' => IntegrationsMap::resolveProtocol($data['protocol']),
            'summary'  => $this->labeler->label($integration->chain, $solutions),
        ]);
    }

    /**
     * Removes an existing edge from the chain (the "disconnect" button in
     * the F3 data-viz's edge editor) — the nodes keep existing; if this was
     * a block's only edge, it now appears isolated in the graph. This is
     * what makes the wiring genuinely free: not every block needs to be
     * connected to another. `viz_layout.edges` is reindexed alongside it (it
     * runs parallel to `chain.edges` by position), otherwise the saved
     * anchors of edges after this one would slide to the wrong edge.
     */
    public function removeEdge(RemoveIntegrationChainEdgeRequest $request, Solution $solution, Integration $integration, int $edge): JsonResponse
    {
        $chain = $integration->chain;
        $edges = $chain['edges'] ?? [];
        abort_if(! $chain || $edge < 0 || ! isset($edges[$edge]), 404);

        array_splice($edges, $edge, 1);
        $chain['edges'] = $edges;

        $vizLayout = $integration->viz_layout;
        if (isset($vizLayout['edges']) && array_key_exists($edge, $vizLayout['edges'])) {
            array_splice($vizLayout['edges'], $edge, 1);
        }

        $integration->update(['chain' => $chain, 'viz_layout' => $vizLayout]);
        $this->sync->handle($integration);

        $integration = $integration->fresh();
        $solutions = $this->labeler->resolveSolutions(collect([$integration->chain]));

        return response()->json([
            'type'    => 'success',
            'message' => 'Ligação removida.',
            'summary' => $this->labeler->label($integration->chain, $solutions),
        ]);
    }

    public function destroy(Solution $solution, Integration $integration): JsonResponse
    {
        $this->authorize('delete', $integration);

        // The integration_solution pivot and the (legacy schema)
        // documentation_blocks have cascadeOnDelete, so the deletion cleans
        // up the links on its own.
        $integration->delete();

        return response()->json([
            'type'           => 'success',
            'message'        => 'Integração removida.',
            'updatableSlots' => [IntegrationsMap::slot($solution)],
        ]);
    }

    private function uniqueSlug(string $name): string
    {
        $base = Str::slug($name) ?: 'integracao';
        $slug = $base;
        $suffix = 1;

        while (Integration::where('slug', $slug)->exists()) {
            $slug = $base . '-' . (++$suffix);
        }

        return $slug;
    }
}
