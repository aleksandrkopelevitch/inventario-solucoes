<?php

namespace App\Support\Documentation;

/**
 * An "árvore" — the third SCENE type: a hierarchy drawn as an animated figure
 * (a system and its modules, layers and what lives in them, a classification).
 *
 * TWO shapes, on purpose, and this class is the bridge between them:
 *
 * - **The model answers NESTED** — `{"root": {"label", "children": [...]}}`.
 *   It is the natural shape to write a hierarchy in, and it cannot dangle: a
 *   child is wherever it was written, so there is no id that can point at
 *   nothing (the failure ChainDraft's string ids exist to catch).
 * - **The page stores it FLAT** — an outline of `{label, detail, level,
 *   highlight}` in reading order, which is what `{% node level="1" … %}`
 *   lines, the editor's indent/outdent rows and the reader's rail all already
 *   speak (the same flat-with-depth shape as the caderno's own page tree).
 *
 * `validate()` reads the nested form; `fromArray()->toArray()` emits the flat
 * one. Depth is capped at three levels (the root, what it contains, and one
 * more): past that a figure stops being readable at a glance, and the page
 * should have been the tree.
 */
final class TreeScene
{
    public const TYPE = 'tree';

    /** Levels below the root: 0 = root, 1 = its children, 2 = grandchildren. */
    public const MAX_LEVEL = 2;

    public const MIN_NODES = 3;

    public const MAX_NODES = 16;

    public const MAX_CHILDREN = 6;

    public const MAX_LABEL = 32;

    public const MAX_DETAIL = 80;

    public const MAX_CAPTION = 120;

    /**
     * @param  list<array{label: string, detail: string, level: int, highlight: bool}>  $nodes
     */
    private function __construct(
        public readonly string $caption,
        public readonly array $nodes,
    ) {}

    /**
     * @param  array<mixed>  $payload  the model's NESTED answer
     * @return list<string> empty when the payload is a valid tree
     */
    public static function validate(array $payload): array
    {
        $problems = [];

        $caption = $payload['caption'] ?? '';

        if (! is_string($caption)) {
            $problems[] = 'O campo "caption" deve ser um texto.';
        } elseif (mb_strlen(trim($caption)) > self::MAX_CAPTION) {
            $problems[] = 'O campo "caption" passa de ' . self::MAX_CAPTION . ' caracteres.';
        }

        $root = $payload['root'] ?? null;

        if (! is_array($root) || array_is_list($root)) {
            return [...$problems, 'O campo "root" é obrigatório e deve ser um objeto (o item do topo da árvore).'];
        }

        $count = 0;
        $highlights = 0;
        self::walk($root, 0, 'raiz', $problems, $count, $highlights);

        if ($count < self::MIN_NODES || $count > self::MAX_NODES) {
            $problems[] = 'A árvore deve ter entre ' . self::MIN_NODES . ' e ' . self::MAX_NODES . ' itens no total (tem ' . $count . ').';
        }

        if ($highlights > 1) {
            $problems[] = 'No máximo UM item pode ter "highlight": true (há ' . $highlights . ').';
        }

        return $problems;
    }

    /**
     * @param  array<mixed>  $node
     * @param  list<string>  $problems
     */
    private static function walk(array $node, int $level, string $where, array &$problems, int &$count, int &$highlights): void
    {
        $count++;

        $label = $node['label'] ?? null;

        if (! is_string($label) || trim($label) === '') {
            $problems[] = "O item \"{$where}\" precisa de um \"label\".";
        } elseif (mb_strlen(trim($label)) > self::MAX_LABEL) {
            $problems[] = "O \"label\" de \"{$where}\" passa de " . self::MAX_LABEL . ' caracteres — encurte.';
        }

        // Named by its label from here on, so a repair round knows which item.
        $name = is_string($label) && trim($label) !== '' ? trim($label) : $where;

        $detail = $node['detail'] ?? '';

        if (! is_string($detail)) {
            $problems[] = "O \"detail\" de \"{$name}\" deve ser um texto.";
        } elseif (mb_strlen(trim($detail)) > self::MAX_DETAIL) {
            $problems[] = "O \"detail\" de \"{$name}\" passa de " . self::MAX_DETAIL . ' caracteres.';
        }

        if (isset($node['highlight']) && ! is_bool($node['highlight'])) {
            $problems[] = "O \"highlight\" de \"{$name}\" deve ser true ou false.";
        } elseif (($node['highlight'] ?? false) === true) {
            $highlights++;
        }

        $children = $node['children'] ?? [];

        if (! is_array($children) || ! array_is_list($children)) {
            $problems[] = "O \"children\" de \"{$name}\" deve ser uma lista.";

            return;
        }

        if ($children === []) {
            return;
        }

        if ($level >= self::MAX_LEVEL) {
            $problems[] = "\"{$name}\" está no terceiro nível e não pode ter filhos — a árvore tem no máximo 3 níveis (o topo e mais dois).";

            return;
        }

        if (count($children) > self::MAX_CHILDREN) {
            $problems[] = "\"{$name}\" tem " . count($children) . ' filhos; o máximo é ' . self::MAX_CHILDREN . ' — agrupe.';
        }

        foreach ($children as $i => $child) {
            if (! is_array($child) || array_is_list($child)) {
                $problems[] = 'O filho ' . ($i + 1) . " de \"{$name}\" deve ser um objeto.";

                continue;
            }

            self::walk($child, $level + 1, $name . ' › ' . ($i + 1), $problems, $count, $highlights);
        }
    }

    /** @param  array<mixed>  $payload  a nested payload `validate()` accepted */
    public static function fromArray(array $payload): self
    {
        $nodes = [];
        self::flatten($payload['root'], 0, $nodes);

        return new self(trim((string) ($payload['caption'] ?? '')), $nodes);
    }

    /**
     * @param  array<mixed>  $node
     * @param  list<array{label: string, detail: string, level: int, highlight: bool}>  $nodes
     */
    private static function flatten(array $node, int $level, array &$nodes): void
    {
        $nodes[] = [
            'label'     => trim((string) $node['label']),
            'detail'    => trim((string) ($node['detail'] ?? '')),
            'level'     => $level,
            'highlight' => ($node['highlight'] ?? false) === true,
        ];

        foreach ($node['children'] ?? [] as $child) {
            self::flatten($child, $level + 1, $nodes);
        }
    }

    /**
     * Repairs an outline somebody EDITED rather than one the model wrote: the
     * first item is the root (level 0) and the only one, nothing sits deeper
     * than one step below the item before it, and nothing past MAX_LEVEL. Used
     * by the renderer so a half-finished edit still draws a tree.
     *
     * @param  list<array{level: int}>  $nodes
     * @return list<array{level: int}>
     */
    public static function normalizeLevels(array $nodes): array
    {
        $previous = -1;

        foreach ($nodes as $i => $node) {
            $level = $i === 0 ? 0 : max(1, min((int) $node['level'], $previous + 1, self::MAX_LEVEL));
            $nodes[$i]['level'] = $level;
            $previous = $level;
        }

        return $nodes;
    }

    /** @return array{type: string, caption: string, nodes: list<array{label: string, detail: string, level: int, highlight: bool}>} */
    public function toArray(): array
    {
        return ['type' => self::TYPE, 'caption' => $this->caption, 'nodes' => $this->nodes];
    }
}
