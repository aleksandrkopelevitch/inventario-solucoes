<?php

namespace App\Http\Controllers;

use App\Actions\SyncIntegrationFromChain;
use App\Contracts\Documentable;
use App\Enums\ChainNodeKind;
use App\Enums\Direction;
use App\Http\Controllers\Concerns\NavigatesSolutionDocs;
use App\Http\Requests\AddIntegrationChainEdgeRequest;
use App\Http\Requests\AddIntegrationChainImageRequest;
use App\Http\Requests\AddIntegrationChainNodeRequest;
use App\Http\Requests\RemoveIntegrationChainEdgeRequest;
use App\Http\Requests\RemoveIntegrationChainNodeRequest;
use App\Http\Requests\RetargetIntegrationChainEdgeRequest;
use App\Http\Requests\SaveIntegrationLayoutRequest;
use App\Http\Requests\StoreIntegrationRequest;
use App\Http\Requests\UpdateIntegrationChainNodeRequest;
use App\Http\Requests\UpdateIntegrationChainProtocolRequest;
use App\Http\Requests\UpdateIntegrationMetaRequest;
use App\Models\Integration;
use App\Models\Solution;
use App\Support\ChainLabeler;
use App\View\Components\Documentation\PagesNav;
use App\View\Components\Solutions\IntegrationMeta;
use App\View\Components\Solutions\IntegrationsMap;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Str;

/**
 * Integrations for a solution — mutation endpoints for the F3 chain data-viz,
 * mounted on the integration's own unified page (`Solutions\IntegrationWorkspace`,
 * the Diagrama tab), not the solution's detail page. The F3 data-viz
 * (`integration-viz.js`) is what authors the topology (chain: nodes/edges,
 * via `updateNode()`/`updateProtocol()`/`addNode()`/`retargetEdge()` below)
 * and the visual layout (`saveLayout()`) — this controller only covers what
 * the data-viz doesn't do: creating a brand-new Integration (`store()`, which
 * then redirects into that page) and renaming/changing the status of an
 * existing one (`update()`, driven by the page's top bar —
 * `Solutions\IntegrationMeta` —, one field at a time), neither of which
 * touches the chain. `SyncIntegrationFromChain`
 * remains the only place that derives participants/source/target/direction
 * from the chain.
 */
class SolutionIntegrationController extends Controller
{
    // Only for `update()`'s response: renaming an integration also renames it
    // in the pages rail rendered beside the editor's top bar, and that rail is
    // built from the same two helpers the documentation controllers use.
    use NavigatesSolutionDocs;

    public function __construct(
        private readonly SyncIntegrationFromChain $sync,
        private readonly ChainLabeler $labeler,
    ) {}

    /**
     * Creates a brand-new Integration with the context solution as the root
     * node — chain = {nodes: [root], edges: []}, ready for the data-viz to
     * freely add blocks (`addNode()`) and wire them (`addEdge()`/`retargetEdge()`).
     * Name is optional (falls back to the root solution's name); initial status
     * is "planned", adjustable afterwards via `update()`.
     */
    public function store(StoreIntegrationRequest $request, Solution $solution): JsonResponse
    {
        $data = $request->validated();
        $chain = [
            'nodes' => [['solution_id' => $solution->id, 'label' => null, 'kind' => ChainNodeKind::System->value]],
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
            'type'    => 'success',
            'message' => 'Integração criada.',
            // Straight into the new integration's own page — an integration
            // created from the solution's list is empty by definition (one
            // root node, no documentation), so the list it was created from
            // has nothing left to show about it. Refreshing that list instead
            // would leave the user one extra click from the only screen where
            // the next step happens.
            'redirect' => route('solutions.integrations.docs.edit', [$solution, $integration]),
        ]);
    }

    /**
     * Renames / changes the status of an existing integration — doesn't touch
     * the chain. Called one field at a time by the editor's top bar
     * (`Solutions\IntegrationMeta`), so the response refreshes both places
     * that name the integration on that screen: the top bar itself and the
     * pages rail beside it.
     */
    public function update(UpdateIntegrationMetaRequest $request, Solution $solution, Integration $integration): JsonResponse
    {
        $integration->update($request->validated());

        return response()->json([
            'type'           => 'success',
            'message'        => 'Integração atualizada.',
            'updatableSlots' => [
                IntegrationMeta::slot($solution, $integration),
                PagesNav::slot(
                    $this->solutionPagesNav($solution, null),
                    $this->solutionIntegrationsNav($solution, $integration),
                    route('solutions.docs.pages.store', $solution),
                    $solution->name,
                    route('solutions.show', $solution),
                ),
            ],
        ]);
    }

    /**
     * Saves the F3 graph's visual layout (block positions, edge endpoint
     * anchors, each block's markdown comment — all keyed by the node's index
     * in the chain —, whether a block/edge is drawn dashed, and the
     * freestanding swimlanes, `lanes`, drawn behind the canvas). Presentation
     * only — the `chain` remains the source of topology, so we do NOT call
     * SyncIntegrationFromChain here nor touch participants/source/target/direction.
     */
    public function saveLayout(SaveIntegrationLayoutRequest $request, Solution $solution, Integration $integration): JsonResponse
    {
        $integration->update([
            'viz_layout' => $request->safe()->only(['nodes', 'edges', 'comments', 'lanes', 'notes', 'theme']),
        ]);

        return response()->json([
            'type'    => 'success',
            'message' => 'Layout salvo.',
        ]);
    }

    /**
     * Updates a single node (F3 data-viz block): its kind (system / decision /
     * actor / start / end — a block can be converted between them) and its
     * title, chosen from a registered Solution (pulls name/logo/attributes) or
     * free text. Still edits the `chain` (source of truth for topology), so
     * SyncIntegrationFromChain runs again: swapping a node's Solution — or
     * turning it into a decision/actor/start/end, which never references one — can
     * change participants/source/target/direction. The root node (index 0)
     * is fixed — it never reaches here (blocked client-side, enforced by the
     * 404 below).
     */
    public function updateNode(UpdateIntegrationChainNodeRequest $request, Solution $solution, Integration $integration, int $node): JsonResponse
    {
        $chain = $integration->chain;
        abort_if(! $chain || $node <= 0 || ! isset($chain['nodes'][$node]), 404);

        $chain['nodes'][$node] = $this->chainNode($request->validated());
        $integration->update(['chain' => $chain]);
        $this->sync->handle($integration);

        $integration = $integration->fresh();
        $solutions = $this->labeler->resolveSolutions(collect([$integration->chain]));
        $comment = $integration->viz_layout['comments'][$node] ?? null;

        return response()->json([
            'type'    => 'success',
            'message' => 'Bloco atualizado.',
            'node'    => IntegrationsMap::resolveNode($integration->chain['nodes'][$node], $solutions, $comment),
            'summary' => $this->labeler->label($integration->chain, $solutions),
        ]);
    }

    /**
     * Removes a block from the chain (the trash in the block's contextual
     * toolbar) and, necessarily, every link touching it — `chain.edges`
     * references nodes BY INDEX, so a node can't leave while an edge still
     * points at it, and every index above the removed one shifts down by one.
     *
     * This is the only chain mutation that has to REINDEX, and it has to do so
     * in four parallel structures at once. Miss one and the canvas silently
     * shows the wrong thing:
     *
     *  - `chain.edges`: edges touching the node are dropped; on the survivors,
     *    a `from`/`to` above the removed index is decremented.
     *  - `viz_layout.nodes` and `viz_layout.comments`: both indexed BY NODE
     *    INDEX — splice the same position, or every block below inherits its
     *    neighbour's position and comment.
     *  - `viz_layout.edges`: indexed BY EDGE INDEX (parallel to `chain.edges`)
     *    — keep only the anchors of the edges that survived, in order. Same
     *    trap `removeEdge()` already documents, one dimension wider.
     *
     * The root node (index 0) is fixed, exactly as in `updateNode()`.
     *
     * The response carries a WHOLE rebuilt graph (`IntegrationsMap::graph()`,
     * the same shape the page was first drawn from) rather than a patch: after
     * a reindex there is no local surgery the client could safely do, so it
     * re-renders. That also keeps the reindexing logic in one language.
     */
    public function removeNode(RemoveIntegrationChainNodeRequest $request, Solution $solution, Integration $integration, int $node): JsonResponse
    {
        $chain = $integration->chain;
        abort_if(! $chain || $node <= 0 || ! isset($chain['nodes'][$node]), 404);

        $nodes = array_values($chain['nodes']);
        array_splice($nodes, $node, 1);

        // Which edges survive, and where they used to live — the old positions
        // are what `viz_layout.edges` is still keyed by.
        $keptEdges = [];
        $keptAnchorIndexes = [];
        foreach (array_values($chain['edges'] ?? []) as $i => $edge) {
            $from = $edge['from'] ?? null;
            $to = $edge['to'] ?? null;

            if ($from === $node || $to === $node) {
                continue;
            }

            if (is_int($from) && $from > $node) {
                $edge['from'] = $from - 1;
            }
            if (is_int($to) && $to > $node) {
                $edge['to'] = $to - 1;
            }

            $keptEdges[] = $edge;
            $keptAnchorIndexes[] = $i;
        }

        $chain['nodes'] = $nodes;
        $chain['edges'] = $keptEdges;

        $integration->update([
            'chain'      => $chain,
            'viz_layout' => $this->layoutWithoutNode($integration->viz_layout, $node, $keptAnchorIndexes),
        ]);
        $this->sync->handle($integration);

        $integration = $integration->fresh();
        $solutions = $this->labeler->resolveSolutions(collect([$integration->chain]));

        return response()->json([
            'type'    => 'success',
            'message' => 'Bloco excluído.',
            'graph'   => (new IntegrationsMap($solution))->graph($integration, $this->labeler, $solutions),
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
     * Appends a new block to the chain (the F3 data-viz's "Adicionar bloco"
     * panel) — a PURE node: its kind (system / decision / actor / start / end)
     * plus a registered Solution or free text, with NO edge and no protocol. Every
     * block is born isolated; wiring is a separate, later gesture — drag an
     * arrow out of any block's port or use "connect mode" (`addEdge()`), or
     * drag an existing edge's endpoint onto it (`retargetEdge()`). Still
     * edits the `chain` (source of truth for topology), so
     * SyncIntegrationFromChain runs again — a solution-backed block becomes a
     * participant even with no edge yet.
     */
    public function addNode(AddIntegrationChainNodeRequest $request, Solution $solution, Integration $integration): JsonResponse
    {
        $chain = $integration->chain;
        abort_if(! $chain, 404);

        $chain['nodes'][] = $this->chainNode($request->validated());
        $newIndex = count($chain['nodes']) - 1;

        $integration->update(['chain' => $chain]);
        $this->sync->handle($integration);

        $integration = $integration->fresh();
        $solutions = $this->labeler->resolveSolutions(collect([$integration->chain]));

        return response()->json([
            'type'    => 'success',
            'message' => 'Bloco adicionado.',
            'node'    => IntegrationsMap::resolveNode($integration->chain['nodes'][$newIndex], $solutions, null),
            'summary' => $this->labeler->label($integration->chain, $solutions),
        ]);
    }

    /**
     * Appends a new IMAGE block to the chain — pasting a picture directly onto
     * the F3 canvas (Ctrl+V), the only way an `App\Enums\ChainNodeKind::Image`
     * block is created. Stores the upload in `Integration`'s `docs` media
     * collection (same collection/serving route as documentation-embedded
     * images) and appends the node referencing it in one request, response
     * shaped exactly like `addNode()`'s so the client reuses the same
     * append-without-redrawing path. Like every other new block, it's born
     * isolated — the image can still send/receive arrows afterwards, exactly
     * like any other block, since edges only ever reference a node by index.
     */
    public function addImageNode(AddIntegrationChainImageRequest $request, Solution $solution, Integration $integration): JsonResponse
    {
        $chain = $integration->chain;
        abort_if(! $chain, 404);

        $media = $integration->addMediaFromRequest('image')->toMediaCollection(Documentable::DOCS_COLLECTION);

        $chain['nodes'][] = [
            'solution_id' => null,
            'label'       => 'Imagem',
            'kind'        => ChainNodeKind::Image->value,
            'media_id'    => $media->id,
        ];
        $newIndex = count($chain['nodes']) - 1;

        $integration->update(['chain' => $chain]);
        $this->sync->handle($integration);

        $integration = $integration->fresh();
        $solutions = $this->labeler->resolveSolutions(collect([$integration->chain]));

        return response()->json([
            'type'    => 'success',
            'message' => 'Imagem adicionada.',
            'node'    => IntegrationsMap::resolveNode($integration->chain['nodes'][$newIndex], $solutions, null),
            'summary' => $this->labeler->label($integration->chain, $solutions),
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
        $newIndex = count($chain['edges']) - 1;

        $integration->update(['chain' => $chain]);
        $this->sync->handle($integration);

        $integration = $integration->fresh();
        $solutions = $this->labeler->resolveSolutions(collect([$integration->chain]));

        return response()->json([
            'type'    => 'success',
            'message' => 'Ligação criada.',
            // The index this edge got in `chain.edges` — every other edge
            // endpoint (protocol update, retarget, remove) addresses edges BY
            // INDEX, so the client must not infer it from its own insertion
            // order: two creates in flight whose responses land out of order
            // would leave every later PATCH/DELETE pointing at the wrong edge.
            'index'    => $newIndex,
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

    /**
     * `viz_layout` with a removed node's entries taken out and everything
     * reindexed to match the new `chain` — see `removeNode()` for why all three
     * arrays have to move together. Returns null unchanged when there's no
     * layout saved yet (nothing to reindex).
     *
     * @param  array<string, mixed>|null  $layout
     * @param  array<int, int>  $keptAnchorIndexes  old `chain.edges` positions that survived, in order
     * @return array<string, mixed>|null
     */
    private function layoutWithoutNode(?array $layout, int $node, array $keptAnchorIndexes): ?array
    {
        if (! $layout) {
            return $layout;
        }

        // Positions and comments are per NODE index.
        foreach (['nodes', 'comments'] as $key) {
            if (isset($layout[$key]) && is_array($layout[$key]) && array_key_exists($node, $layout[$key])) {
                $values = array_values($layout[$key]);
                array_splice($values, $node, 1);
                $layout[$key] = $values;
            }
        }

        // Anchors are per EDGE index: rebuild from the surviving positions.
        if (isset($layout['edges']) && is_array($layout['edges'])) {
            $anchors = array_values($layout['edges']);
            $layout['edges'] = array_values(array_map(
                fn (int $old) => $anchors[$old] ?? ['from' => 'r', 'to' => 'l'],
                $keptAnchorIndexes,
            ));
        }

        return $layout;
    }

    /**
     * A chain node in storage shape, from the validated fields of
     * `AddIntegrationChainNodeRequest`/`UpdateIntegrationChainNodeRequest`
     * (both validate the same three fields — see `ValidatesChainNode`).
     * Built explicitly, in a fixed key order, so `chain.nodes` never depends
     * on which keys the request happened to carry.
     *
     * @param  array{solution_id?: int|null, label?: string|null, kind?: string|null}  $data
     * @return array{solution_id: int|null, label: string|null, kind: string}
     */
    private function chainNode(array $data): array
    {
        return [
            'solution_id' => $data['solution_id'] ?? null,
            'label'       => $data['label'] ?? null,
            'kind'        => $data['kind'] ?? ChainNodeKind::System->value,
        ];
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
