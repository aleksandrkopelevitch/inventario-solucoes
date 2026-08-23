<?php

namespace App\Contracts;

use Illuminate\Database\Eloquent\Model;
use Spatie\MediaLibrary\HasMedia;

/**
 * Something the F3 canvas can draw and edit.
 *
 * The canvas (`integration-viz.js`, ~4.5k lines) contains **no route of its
 * own**: every endpoint it calls arrives inside the graph payload it is drawn
 * from (`nodeAddUrl`, `edgeRetargetUrl`, `saveUrl`, …). That is what makes a
 * second owner cheap — the client never learns there is one. This contract is
 * the server half of the same idea: `Concerns\EditsChain` performs the nine
 * chain mutations against anything that implements it.
 *
 * Two implementations, and the difference between them is the whole reason
 * this exists:
 *
 * - `Integration` — its chain drives DERIVED columns (participants,
 *   source/target, direction, protocol), re-derived by
 *   `SyncIntegrationFromChain` after every mutation.
 * - `SubmissionDiagram` — a proposal's AS IS / TO BE. Nothing is derived from
 *   it: a submission is a thing being argued about, not a record of what
 *   exists, and letting a rejected proposal's topology write into the catalog
 *   is exactly the drift this module exists to remove.
 *
 * `afterChainMutation()` is where that difference lives, and it is the only
 * hook: keeping it a single method is what stops the two owners' rules from
 * quietly growing apart.
 *
 * @mixin Model
 */
interface ChainCanvas extends HasMedia
{
    /**
     * The topology: `{nodes: [...], edges: [...]}`, or null before anything
     * has been drawn.
     *
     * @return array<string, mixed>|null
     */
    public function chainData(): ?array;

    /**
     * Purely visual: node positions, edge anchors, per-node comments, lanes,
     * notes, theme. Must NEVER drive topology.
     *
     * @return array<string, mixed>|null
     */
    public function vizLayout(): ?array;

    /**
     * Writes either or both, in one update.
     *
     * Both are passed together because the one mutation that reindexes
     * (removing a node) has to move them in lockstep — `chain.edges` is keyed
     * by node index and `viz_layout.nodes`/`comments` are too. Two separate
     * writes is how they drift apart.
     *
     * @param  array<string, mixed>|null  $chain  null leaves the chain untouched
     * @param  array<string, mixed>|null  $layout  null leaves the layout untouched
     */
    public function writeChain(?array $chain = null, ?array $layout = null): void;

    /**
     * Runs after every chain write. `Integration` re-derives its columns here;
     * a submission's diagram has nothing to derive and does nothing.
     */
    public function afterChainMutation(): void;

    /**
     * Media collection a picture pasted onto the canvas is stored in.
     *
     * It has to be `Documentable::DOCS_COLLECTION` for both owners:
     * `MediaController::show()` serves `/files/{id}` only for that collection
     * name, and an image node references its picture by exactly that URL.
     */
    public function chainImageCollection(): string;

    /**
     * Media collection the canvas publishes its rendered PNG into on save —
     * the picture the CATI deck embeds. `singleFile()`, always: only the
     * current one is ever wanted.
     */
    public function chainDiagramCollection(): string;

    /**
     * Every endpoint the canvas needs, keyed as the graph payload expects
     * (`saveUrl`, `nodeAddUrl`, `nodeUpdateUrl`, `nodeRemoveUrl`,
     * `imageAddUrl`, `edgeAddUrl`, `edgeUpdateUrl`, `edgeRetargetUrl`,
     * `edgeRemoveUrl`, `diagramUrl`). `NODE_INDEX`/`EDGE_INDEX` placeholders
     * are substituted client-side.
     *
     * @return array<string, string>
     */
    public function chainUrls(): array;
}
