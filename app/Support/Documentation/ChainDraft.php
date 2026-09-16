<?php

namespace App\Support\Documentation;

use App\Enums\ChainNodeKind;

/**
 * A topology the model proposed from a page's prose, before anything has been
 * created from it.
 *
 * This is deliberately NOT `chain` (see `.claude/rules/diagram-chain-canvas.md`).
 * A chain addresses its edges by INTEGER INDEX into `nodes`, which is the right
 * shape for a canvas that reindexes on delete and the wrong one to ask a
 * language model for: an off-by-one in `{"from": 3}` is invisible in the output,
 * survives every syntactic check, and draws a confident arrow between the wrong
 * two systems. Here a node carries a string `id` it chose itself and an edge
 * names those ids, so a wrong reference is a name that doesn't exist and
 * `validate()` says so. `CreateDiagramFromDraft` does the conversion to indices
 * once, against a map it built itself.
 *
 * A `solution` is likewise a NAME, not an id: the model is shown the catalog's
 * names and can only echo one back. Resolving it happens later and exactly, and
 * a name that resolves to nothing becomes a free-text block rather than a guess
 * — see the action for why that matters more here than it looks.
 *
 * Nothing in this class touches the database, so the whole IR contract can be
 * tested without one.
 */
final class ChainDraft
{
    /**
     * Caps, stated here because they are part of the contract the prompt
     * promises. A drawing of more than this is not a drawing anybody reads; it
     * is also the shape a model falls into when it decides to diagram every
     * sentence on the page instead of the flow the page describes.
     */
    public const MAX_NODES = 40;

    public const MAX_EDGES = 80;

    public const MAX_NAME = 120;

    public const MAX_LABEL = 80;

    public const MAX_PROTOCOL = 40;

    /** The arrow vocabulary is the chain's own — `AddChainEdgeRequest` validates the same three. */
    public const ARROWS = ['->', '<-', '<->'];

    /**
     * @param  list<array{id: string, kind: string, label: ?string, solution: ?string}>  $nodes
     * @param  list<array{from: string, to: string, arrow: string, protocol: ?string}>  $edges
     */
    private function __construct(
        public readonly string $name,
        public readonly array $nodes,
        public readonly array $edges,
    ) {}

    /**
     * Everything wrong with a payload, in PT-BR, as sentences a model can act
     * on — each one names the offending id or index, because "nó inválido" sent
     * back into a repair round produces a second guess rather than a fix.
     *
     * The list is also what the operator sees if the repair round fails, which
     * is the other reason it is written for a reader rather than as codes.
     *
     * @param  array<mixed>  $payload
     * @return list<string> empty when the payload is a valid draft
     */
    public static function validate(array $payload): array
    {
        $problems = [];

        $name = $payload['name'] ?? null;

        if (! is_string($name) || trim($name) === '') {
            $problems[] = 'O campo "name" é obrigatório e deve ser um texto não vazio.';
        } elseif (mb_strlen(trim($name)) > self::MAX_NAME) {
            $problems[] = 'O campo "name" passa de ' . self::MAX_NAME . ' caracteres.';
        }

        $nodes = $payload['nodes'] ?? null;

        if (! is_array($nodes) || $nodes === []) {
            return [...$problems, 'O campo "nodes" é obrigatório e precisa de pelo menos um bloco.'];
        }

        if (count($nodes) > self::MAX_NODES) {
            $problems[] = 'O diagrama tem ' . count($nodes) . ' blocos; o máximo é ' . self::MAX_NODES . '.';
        }

        $ids = [];

        foreach (array_values($nodes) as $i => $node) {
            $where = 'nodes[' . $i . ']';

            if (! is_array($node)) {
                $problems[] = $where . ' não é um objeto.';

                continue;
            }

            $id = $node['id'] ?? null;

            if (! is_string($id) || trim($id) === '') {
                $problems[] = $where . ' não tem "id".';
            } elseif (isset($ids[$id])) {
                $problems[] = 'O id "' . $id . '" aparece em mais de um bloco; cada bloco precisa de um id único.';
            } else {
                $ids[$id] = true;
            }

            $kind = $node['kind'] ?? null;
            $kindCase = is_string($kind) ? ChainNodeKind::tryFrom($kind) : null;

            // `Image` is excluded for the same reason `pickable()` excludes it:
            // an image block exists only because somebody pasted a picture, and
            // it carries a `media_id` no model can produce.
            if ($kindCase === null || ! $kindCase->pickable()) {
                $problems[] = $where . ' tem "kind" inválido (' . json_encode($kind) . '); use um de: '
                    . implode(', ', self::pickableKinds()) . '.';
            }

            $label = $node['label'] ?? null;

            if ($label !== null && ! is_string($label)) {
                $problems[] = $where . ' tem "label" que não é texto.';
            } elseif (is_string($label) && mb_strlen($label) > self::MAX_LABEL) {
                $problems[] = $where . ' tem "label" com mais de ' . self::MAX_LABEL . ' caracteres.';
            }

            $solution = $node['solution'] ?? null;

            if ($solution !== null && ! is_string($solution)) {
                $problems[] = $where . ' tem "solution" que não é texto.';
            }

            // Only a `system` block may name a catalog Solution — the same rule
            // `ChainNodeKind::referencesSolution()` enforces on the way in, said
            // here so a draft can never produce a node the canvas would then
            // read as free text while the model believed it had linked a system.
            if (filled($solution) && $kindCase !== null && ! $kindCase->referencesSolution()) {
                $problems[] = $where . ' é do tipo "' . $kind . '" e não pode ter "solution"; só "system" referencia uma solução do catálogo.';
            }

            // A block with neither is a block with nothing written on it.
            if (blank($label) && blank($solution) && $kindCase !== null && ! in_array($kindCase, [ChainNodeKind::Start, ChainNodeKind::End], true)) {
                $problems[] = $where . ' precisa de "label" ou de "solution".';
            }
        }

        $edges = $payload['edges'] ?? [];

        if (! is_array($edges)) {
            return [...$problems, 'O campo "edges" precisa ser uma lista.'];
        }

        if (count($edges) > self::MAX_EDGES) {
            $problems[] = 'O diagrama tem ' . count($edges) . ' ligações; o máximo é ' . self::MAX_EDGES . '.';
        }

        foreach (array_values($edges) as $i => $edge) {
            $where = 'edges[' . $i . ']';

            if (! is_array($edge)) {
                $problems[] = $where . ' não é um objeto.';

                continue;
            }

            foreach (['from', 'to'] as $end) {
                $ref = $edge[$end] ?? null;

                if (! is_string($ref) || trim($ref) === '') {
                    $problems[] = $where . ' não tem "' . $end . '".';
                } elseif (! isset($ids[$ref])) {
                    $problems[] = $where . ' aponta para o id "' . $ref . '", que não existe em "nodes".';
                }
            }

            if (isset($edge['from'], $edge['to']) && is_string($edge['from']) && $edge['from'] === $edge['to']) {
                $problems[] = $where . ' liga o bloco "' . $edge['from'] . '" a ele mesmo.';
            }

            $arrow = $edge['arrow'] ?? null;

            if (! is_string($arrow) || ! in_array($arrow, self::ARROWS, true)) {
                $problems[] = $where . ' tem "arrow" inválido (' . json_encode($arrow) . '); use "->", "<-" ou "<->".';
            }

            $protocol = $edge['protocol'] ?? null;

            if ($protocol !== null && ! is_string($protocol)) {
                $problems[] = $where . ' tem "protocol" que não é texto.';
            } elseif (is_string($protocol) && mb_strlen($protocol) > self::MAX_PROTOCOL) {
                $problems[] = $where . ' tem "protocol" com mais de ' . self::MAX_PROTOCOL . ' caracteres.';
            }
        }

        return $problems;
    }

    /**
     * Assumes `validate()` returned nothing — normalizes rather than checks.
     *
     * @param  array<mixed>  $payload
     */
    public static function fromArray(array $payload): self
    {
        $nodes = [];

        foreach (array_values($payload['nodes']) as $node) {
            $label = isset($node['label']) && is_string($node['label']) ? trim($node['label']) : null;
            $solution = isset($node['solution']) && is_string($node['solution']) ? trim($node['solution']) : null;

            $nodes[] = [
                'id'       => trim($node['id']),
                'kind'     => $node['kind'],
                'label'    => $label !== '' ? $label : null,
                'solution' => $solution !== '' ? $solution : null,
            ];
        }

        $edges = [];

        foreach (array_values($payload['edges'] ?? []) as $edge) {
            $protocol = isset($edge['protocol']) && is_string($edge['protocol']) ? trim($edge['protocol']) : null;

            $edges[] = [
                'from'     => trim($edge['from']),
                'to'       => trim($edge['to']),
                'arrow'    => $edge['arrow'],
                'protocol' => $protocol !== '' ? $protocol : null,
            ];
        }

        return new self(trim($payload['name']), $nodes, $edges);
    }

    /**
     * Every distinct Solution name the draft claims, for the one query that
     * resolves them.
     *
     * @return list<string>
     */
    public function solutionNames(): array
    {
        $names = [];

        foreach ($this->nodes as $node) {
            if (filled($node['solution'])) {
                $names[$node['solution']] = true;
            }
        }

        return array_keys($names);
    }

    /** @return list<string> */
    private static function pickableKinds(): array
    {
        return array_values(array_map(
            fn (ChainNodeKind $kind) => $kind->value,
            array_filter(ChainNodeKind::cases(), fn (ChainNodeKind $kind) => $kind->pickable()),
        ));
    }
}
