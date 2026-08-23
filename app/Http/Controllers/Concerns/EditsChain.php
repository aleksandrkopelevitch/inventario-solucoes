<?php

namespace App\Http\Controllers\Concerns;

use App\Contracts\ChainCanvas;
use App\Enums\ChainNodeKind;
use App\Support\ChainGraph;
use App\Support\ChainLabeler;
use App\View\Components\Solutions\IntegrationsMap;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\UploadedFile;

/**
 * The nine mutations the F3 canvas performs, against any `ChainCanvas`.
 *
 * These used to live in `SolutionIntegrationController`, spelled against
 * `Integration` directly. They moved here when a submission's AS IS / TO BE
 * drawings became a second owner, and the move is the point: the chain's
 * rules — indices, reindexing, what is allowed to reference a Solution, which
 * node is protected — are subtle enough that a second copy would diverge
 * within a release, and the divergence would be invisible until a diagram
 * silently showed the wrong thing.
 *
 * What is NOT here is what differs per owner: authorization (the FormRequests
 * already did that before this trait is reached) and whatever has to happen
 * after a write, which is `ChainCanvas::afterChainMutation()` — an Integration
 * re-derives its columns there, a submission's diagram derives nothing.
 *
 * Every method answers in the exact shape the canvas expects. The client
 * patches its own DOM from these fields, so the names are a contract, not an
 * implementation detail.
 */
trait EditsChain
{
    /**
     * Saves the canvas's visual layout — block positions, edge endpoint
     * anchors, per-block markdown comments (all keyed by the node's index in
     * the chain), dashed flags, the freestanding swimlanes and notes.
     *
     * Presentation only. The chain remains the source of topology, so this
     * deliberately does NOT call `afterChainMutation()`: nothing derived may
     * ever move because someone dragged a box.
     *
     * @param  array<string, mixed>  $layout
     */
    protected function saveChainLayout(ChainCanvas $owner, array $layout): JsonResponse
    {
        $owner->writeChain(layout: $layout);

        return response()->json(['type' => 'success', 'message' => 'Layout salvo.']);
    }

    /**
     * Updates a single block: its kind (system / decision / actor / start /
     * end — a block can be converted between them) and its title, either a
     * registered Solution or free text.
     *
     * The root block (index 0) is fixed and never reaches here — blocked
     * client-side and enforced by the 404 below.
     *
     * @param  array{solution_id?: int|null, label?: string|null, kind?: string|null}  $data
     */
    protected function updateChainNode(ChainCanvas $owner, array $data, int $node): JsonResponse
    {
        $chain = $owner->chainData();
        abort_if(! $chain || $node <= 0 || ! isset($chain['nodes'][$node]), 404);

        $chain['nodes'][$node] = $this->chainNodeShape($data);
        $owner->writeChain(chain: $chain);
        $owner->afterChainMutation();

        $owner->refresh();
        $solutions = $this->chainLabeler()->resolveSolutions(collect([$owner->chainData()]));
        $comment = $owner->vizLayout()['comments'][$node] ?? null;

        return response()->json([
            'type'    => 'success',
            'message' => 'Bloco atualizado.',
            'node'    => IntegrationsMap::resolveNode($owner->chainData()['nodes'][$node], $solutions, $comment),
            'summary' => $this->chainLabeler()->label($owner->chainData(), $solutions),
        ]);
    }

    /**
     * Removes a block and, necessarily, every link touching it — `chain.edges`
     * references nodes BY INDEX, so a node cannot leave while an edge still
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
     *    — keep only the anchors of the edges that survived, in order.
     *
     * The root node (index 0) is fixed, exactly as in `updateChainNode()`.
     *
     * The response carries a WHOLE rebuilt graph rather than a patch: after a
     * reindex there is no local surgery the client could safely do, so it
     * re-renders. That also keeps the reindexing logic in one language.
     */
    protected function removeChainNode(ChainCanvas $owner, int $node): JsonResponse
    {
        $chain = $owner->chainData();
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

        $owner->writeChain(
            chain: $chain,
            layout: $this->layoutWithoutNode($owner->vizLayout(), $node, $keptAnchorIndexes),
        );
        $owner->afterChainMutation();

        $owner->refresh();
        $labeler = $this->chainLabeler();
        $solutions = $labeler->resolveSolutions(collect([$owner->chainData()]));

        return response()->json([
            'type'    => 'success',
            'message' => 'Bloco excluído.',
            'graph'   => ChainGraph::for($owner, $labeler, $solutions),
            'summary' => $labeler->label($owner->chainData(), $solutions),
        ]);
    }

    /**
     * Updates the protocol and/or direction (`arrow`) of a single link.
     *
     * Unlike nodes there is no protected edge; `$edge` is the index in
     * `chain.edges`.
     *
     * @param  array{protocol?: string|null, arrow?: string}  $data
     */
    protected function updateChainProtocol(ChainCanvas $owner, array $data, int $edge): JsonResponse
    {
        $chain = $owner->chainData();
        $edges = $chain['edges'] ?? [];
        abort_if(! $chain || $edge < 0 || ! isset($edges[$edge]), 404);

        $edges[$edge]['protocol'] = $data['protocol'];

        if (array_key_exists('arrow', $data)) {
            $edges[$edge]['arrow'] = $data['arrow'];
        }

        $chain['edges'] = $edges;

        $owner->writeChain(chain: $chain);
        $owner->afterChainMutation();

        return response()->json([
            'type'     => 'success',
            'message'  => 'Ligação atualizada.',
            'protocol' => IntegrationsMap::resolveProtocol($edges[$edge]['protocol']),
            'arrow'    => $edges[$edge]['arrow'] ?? '->',
        ]);
    }

    /**
     * Appends a PURE block: kind + Solution/free text, with NO edge and no
     * protocol. Every block is born isolated; wiring is a separate, later
     * gesture (`addChainEdge()` / `retargetChainEdge()`).
     *
     * @param  array{solution_id?: int|null, label?: string|null, kind?: string|null}  $data
     */
    protected function addChainNode(ChainCanvas $owner, array $data): JsonResponse
    {
        $chain = $owner->chainData();
        abort_if(! $chain, 404);

        $chain['nodes'][] = $this->chainNodeShape($data);

        return $this->appendedNode($owner, $chain, 'Bloco adicionado.');
    }

    /**
     * Appends an IMAGE block — pasting a picture straight onto the canvas
     * (Ctrl+V), the only way an `App\Enums\ChainNodeKind::Image` block is ever
     * created. Stores the upload in the owner's image collection (always the
     * `docs` name, since `/files/{id}` only serves that) and appends the node
     * referencing it in one request, shaped exactly like `addChainNode()`'s so
     * the client reuses the same append-without-redrawing path.
     */
    protected function addChainImageNode(ChainCanvas $owner, UploadedFile $image): JsonResponse
    {
        $chain = $owner->chainData();
        abort_if(! $chain, 404);

        $media = $owner->addMedia($image)->toMediaCollection($owner->chainImageCollection());

        $chain['nodes'][] = [
            'solution_id' => null,
            'label'       => 'Imagem',
            'kind'        => ChainNodeKind::Image->value,
            'media_id'    => $media->id,
        ];

        return $this->appendedNode($owner, $chain, 'Imagem adicionada.');
    }

    /**
     * Rewires one endpoint (`from` or `to`) of an existing link to any other
     * block. This is what turns the chain into a free graph instead of a
     * straight line.
     *
     * @param  array{end: string, node: int}  $data
     */
    protected function retargetChainEdge(ChainCanvas $owner, array $data, int $edge): JsonResponse
    {
        $chain = $owner->chainData();
        $edges = $chain['edges'] ?? [];
        abort_if(! $chain || $edge < 0 || ! isset($edges[$edge]), 404);

        $edges[$edge][$data['end']] = $data['node'];
        $chain['edges'] = $edges;

        $owner->writeChain(chain: $chain);
        $owner->afterChainMutation();

        $owner->refresh();
        $labeler = $this->chainLabeler();

        return response()->json([
            'type'    => 'success',
            'message' => 'Ligação atualizada.',
            'from'    => $edges[$edge]['from'],
            'to'      => $edges[$edge]['to'],
            'summary' => $labeler->label($owner->chainData(), $labeler->resolveSolutions(collect([$owner->chainData()]))),
        ]);
    }

    /**
     * Creates a brand-new link between two blocks that already exist — the
     * canvas's "connect mode", or dragging an arrow out of a block's port.
     *
     * @param  array{from: int, to: int, arrow: string, protocol: string|null}  $data
     */
    protected function addChainEdge(ChainCanvas $owner, array $data): JsonResponse
    {
        $chain = $owner->chainData();
        abort_if(! $chain, 404);

        $chain['edges'][] = [
            'from'     => $data['from'],
            'to'       => $data['to'],
            'arrow'    => $data['arrow'],
            'protocol' => $data['protocol'],
        ];
        $newIndex = count($chain['edges']) - 1;

        $owner->writeChain(chain: $chain);
        $owner->afterChainMutation();

        $owner->refresh();
        $labeler = $this->chainLabeler();

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
            'summary'  => $labeler->label($owner->chainData(), $labeler->resolveSolutions(collect([$owner->chainData()]))),
        ]);
    }

    /**
     * Removes a link without removing its blocks — this is what makes the
     * wiring genuinely free: not every block needs to be connected.
     * `viz_layout.edges` is reindexed alongside it (it runs parallel to
     * `chain.edges` by position), otherwise the saved anchors of every later
     * edge would slide onto the wrong one.
     */
    protected function removeChainEdge(ChainCanvas $owner, int $edge): JsonResponse
    {
        $chain = $owner->chainData();
        $edges = $chain['edges'] ?? [];
        abort_if(! $chain || $edge < 0 || ! isset($edges[$edge]), 404);

        array_splice($edges, $edge, 1);
        $chain['edges'] = $edges;

        $layout = $owner->vizLayout();
        if (isset($layout['edges']) && array_key_exists($edge, $layout['edges'])) {
            array_splice($layout['edges'], $edge, 1);
        }

        $owner->writeChain(chain: $chain, layout: $layout);
        $owner->afterChainMutation();

        $owner->refresh();
        $labeler = $this->chainLabeler();

        return response()->json([
            'type'    => 'success',
            'message' => 'Ligação removida.',
            'summary' => $labeler->label($owner->chainData(), $labeler->resolveSolutions(collect([$owner->chainData()]))),
        ]);
    }

    /* ------------------------------------------------------------------ */
    /*  Shared internals */
    /* ------------------------------------------------------------------ */

    /** Writes an appended node and answers in the canvas's append-in-place shape. */
    private function appendedNode(ChainCanvas $owner, array $chain, string $message): JsonResponse
    {
        $newIndex = count($chain['nodes']) - 1;

        $owner->writeChain(chain: $chain);
        $owner->afterChainMutation();

        $owner->refresh();
        $labeler = $this->chainLabeler();
        $solutions = $labeler->resolveSolutions(collect([$owner->chainData()]));

        return response()->json([
            'type'    => 'success',
            'message' => $message,
            'node'    => IntegrationsMap::resolveNode($owner->chainData()['nodes'][$newIndex], $solutions, null),
            'summary' => $labeler->label($owner->chainData(), $solutions),
        ]);
    }

    /**
     * `viz_layout` with a removed node's entries taken out and everything
     * reindexed to match the new chain — see `removeChainNode()` for why all
     * three arrays have to move together. Returns null unchanged when there is
     * no layout saved yet.
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
     * A chain node in storage shape, from the validated fields both node
     * requests share (`Concerns\ValidatesChainNode`). Built explicitly, in a
     * fixed key order, so `chain.nodes` never depends on which keys the
     * request happened to carry.
     *
     * @param  array{solution_id?: int|null, label?: string|null, kind?: string|null}  $data
     * @return array{solution_id: int|null, label: string|null, kind: string}
     */
    private function chainNodeShape(array $data): array
    {
        return [
            'solution_id' => $data['solution_id'] ?? null,
            'label'       => $data['label'] ?? null,
            'kind'        => $data['kind'] ?? ChainNodeKind::System->value,
        ];
    }

    private function chainLabeler(): ChainLabeler
    {
        return app(ChainLabeler::class);
    }
}
