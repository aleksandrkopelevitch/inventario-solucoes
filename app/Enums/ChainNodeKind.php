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
 * `Decision` and `Actor` are free text only (a person/area or a branch in the
 * flow is never a catalog Solution), so they never become participants in
 * `SyncIntegrationFromChain` — they still count toward their neighbors'
 * in/out degree, exactly like a free-text system node.
 */
enum ChainNodeKind: string
{
    case System = 'system';
    case Decision = 'decision';
    case Actor = 'actor';

    public function label(): string
    {
        return match ($this) {
            self::System   => 'Sistema',
            self::Decision => 'Decisão',
            self::Actor    => 'Ator (pessoa/área)',
        };
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
        };
    }

    /** Placeholder of the free-text input in the block panels (data-viz F3). */
    public function placeholder(): string
    {
        return match ($this) {
            self::System   => 'Nome do sistema externo',
            self::Decision => 'Pedido aprovado?',
            self::Actor    => 'Cliente, vendedor, fiscal…',
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
