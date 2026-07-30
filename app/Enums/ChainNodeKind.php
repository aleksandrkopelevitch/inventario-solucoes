<?php

namespace App\Enums;

/**
 * What a chain node (`chain.nodes[i]`) represents in the F3 data-viz. Kept in
 * the node itself (`kind`), alongside `solution_id`/`label`, so the topology's
 * source of truth also carries the semantics of each block.
 *
 * `System` is the historical (and default) kind — a registered Solution or a
 * free-text external system —, which is why nodes stored before this enum
 * existed have no `kind` key at all: `fromNode()` reads them as `System`.
 * `Decision`, `Actor`, `Start` and `End` are free text only (a person/area, a
 * branch in the flow, or a flow's start/end marker is never a catalog
 * Solution), so they never become participants in `SyncIntegrationFromChain`
 * — they still count toward their neighbors' in/out degree, exactly like a
 * free-text system node. `Start`/`End` are drawn as a small solid-color
 * circle (green/red) with the kind's icon inside and the label below —
 * see `integration-viz.js::paintNode()` — the flowchart convention for a
 * process' entry/exit points; nothing stops a chain from having zero, one, or
 * several of each.
 *
 * `Image` is a pasted picture (`chain.nodes[i].media_id`, into
 * `Integration`'s `docs` media collection) — also never a participant, and
 * NOT `pickable()`: it never appears in the kind picker cards
 * (`integration-viz.js::buildKindPicker()`), the only way to create one is
 * pasting an image directly on the canvas (Ctrl+V), which posts straight to
 * its own endpoint (`SolutionIntegrationController::addImageNode()`) rather
 * than going through `ValidatesChainNode`. Excluding it from `pickable()` — and
 * from the `Rule::in` the trait validates `kind` against — is what guarantees
 * an image node is never created/converted without a `media_id` alongside it.
 */
enum ChainNodeKind: string
{
    case System = 'system';
    case Decision = 'decision';
    case Actor = 'actor';
    case Start = 'start';
    case End = 'end';
    case Image = 'image';

    public function label(): string
    {
        return match ($this) {
            self::System   => 'Sistema',
            self::Decision => 'Decisão',
            self::Actor    => 'Ator (pessoa/área)',
            self::Start    => 'Início',
            self::End      => 'Fim',
            self::Image    => 'Imagem',
        };
    }

    /**
     * Whether this kind can be chosen from the kind picker cards (the
     * "Adicionar bloco" panel and a block's own title editor) — false only for
     * `Image`, which is created exclusively by pasting a picture on the canvas.
     * `ValidatesChainNode` validates `kind` against exactly this subset, so the
     * generic node endpoints (`addNode()`/`updateNode()`) can never produce or
     * convert a block into `Image` without going through `addImageNode()`.
     */
    public function pickable(): bool
    {
        return $this !== self::Image;
    }

    /** Only a system node can point at a registered Solution. */
    public function referencesSolution(): bool
    {
        return $this === self::System;
    }

    /**
     * Heroicon (outline) drawn inside the block, for the kinds that don't have
     * a solution logo/initial to show as avatar. Rendered server-side
     * (`Heroicons::outlineSvg()`) into the graph payload, since
     * `integration-viz.js` builds the nodes in plain DOM.
     */
    public function icon(): ?string
    {
        return match ($this) {
            self::System   => null,
            self::Decision => 'question-mark-circle',
            self::Actor    => 'user',
            self::Start    => 'play',
            self::End      => 'stop',
            // Fallback glyph only: a well-formed Image node always has a
            // `media_id` and draws the picture itself instead — this only
            // shows up if the underlying media is ever missing.
            self::Image => 'photo',
        };
    }

    /**
     * Heroicon (outline) for the visual kind picker (icon + label cards,
     * `integration-viz.js::buildKindPicker()`) — always non-null, unlike
     * `icon()` above. `System` has no fixed on-canvas icon (a system block
     * shows the Solution's logo, or nothing for free text), but the picker
     * still needs SOME glyph to represent the "Sistema" card, hence a
     * separate method rather than reusing `icon()`.
     */
    public function pickerIcon(): string
    {
        return match ($this) {
            self::System   => 'cpu-chip',
            self::Decision => 'question-mark-circle',
            self::Actor    => 'user',
            self::Start    => 'play',
            self::End      => 'stop',
            self::Image    => 'photo',
        };
    }

    /**
     * Placeholder of the free-text input in the block panels (data-viz F3).
     * Never actually shown for `Image` (not `pickable()`, so it never reaches
     * that form), kept only for the match's exhaustiveness.
     */
    public function placeholder(): string
    {
        return match ($this) {
            self::System   => 'Nome do sistema externo',
            self::Decision => 'Pedido aprovado?',
            self::Actor    => 'Cliente, vendedor, fiscal…',
            self::Start    => 'Início',
            self::End      => 'Fim',
            self::Image    => '',
        };
    }

    /**
     * Text a block of this kind falls back to when the user leaves the
     * free-text field blank (`ValidatesChainNode::prepareForValidation()`) —
     * `Start`/`End` almost always just say "Início"/"Fim", so picking the
     * kind alone is enough to create the block, with the label still free to
     * customize. Null for every other kind, where free text stays required.
     */
    public function defaultLabel(): ?string
    {
        return match ($this) {
            self::Start => 'Início',
            self::End   => 'Fim',
            default     => null,
        };
    }

    /**
     * Kind of a raw chain node — `System` for anything unknown or absent
     * (nodes stored before this enum existed).
     *
     * @param  array{kind?: string|null}  $node
     */
    public static function fromNode(array $node): self
    {
        return self::tryFrom((string) ($node['kind'] ?? '')) ?? self::System;
    }
}
