<?php

namespace App\Support\Cati;

/**
 * Checks a deck spec before anything tries to render it.
 *
 * Today the spec is built deterministically (BuildDeckSpec), so this mostly
 * guards against a section shaped in a way slides can't hold. It exists now
 * because the next slice hands spec-writing to a model, and this is the
 * validator its correction loop reports against — the same discipline the
 * flowSpec generator already follows: the model writes JSON, code writes the
 * file, and nothing reaches the file until it validates.
 */
class DeckSpecValidator
{
    /** Layout keys the renderer knows how to place. */
    public const LAYOUTS = ['cover', 'content', 'closing'];

    /** A slide with more rows than this stops being readable from six metres away. */
    private const MAX_TABLE_ROWS = 12;

    private const MAX_TABLE_COLUMNS = 6;

    /**
     * @param  array<string, mixed>  $spec
     * @return list<string> problems found (empty = valid)
     */
    public function validate(array $spec): array
    {
        $problems = [];

        if (! isset($spec['slides']) || ! is_array($spec['slides']) || $spec['slides'] === []) {
            return ['O deck não tem slide nenhum.'];
        }

        foreach (array_values($spec['slides']) as $index => $slide) {
            $where = 'slide ' . ($index + 1);

            if (! is_array($slide)) {
                $problems[] = "{$where}: não é um objeto.";

                continue;
            }

            if (! in_array($slide['layout'] ?? null, self::LAYOUTS, true)) {
                $problems[] = "{$where}: layout desconhecido (" . json_encode($slide['layout'] ?? null) . ').';
            }

            if (blank($slide['title'] ?? null)) {
                $problems[] = "{$where}: sem título.";
            }

            foreach (array_values($slide['blocks'] ?? []) as $blockIndex => $block) {
                $problems = [...$problems, ...$this->validateBlock($block, "{$where}, bloco " . ($blockIndex + 1))];
            }
        }

        return $problems;
    }

    /**
     * @param  mixed  $block
     * @return list<string>
     */
    private function validateBlock($block, string $where): array
    {
        if (! is_array($block)) {
            return ["{$where}: não é um objeto."];
        }

        $type = $block['type'] ?? null;

        if ($type === 'table') {
            $columns = $block['columns'] ?? [];
            $rows = $block['rows'] ?? [];

            if (! is_array($columns) || $columns === []) {
                return ["{$where}: tabela sem colunas."];
            }

            $problems = [];

            if (count($columns) > self::MAX_TABLE_COLUMNS) {
                $problems[] = "{$where}: tabela com " . count($columns) . ' colunas (máximo ' . self::MAX_TABLE_COLUMNS . ').';
            }

            if (count($rows) > self::MAX_TABLE_ROWS) {
                $problems[] = "{$where}: tabela com " . count($rows) . ' linhas (máximo ' . self::MAX_TABLE_ROWS . ').';
            }

            foreach (array_values($rows) as $i => $row) {
                if (! is_array($row) || count($row) !== count($columns)) {
                    // A ragged row shifts every cell after it into the wrong
                    // column — worse than an error, because it looks fine.
                    $problems[] = "{$where}: linha " . ($i + 1) . ' não tem ' . count($columns) . ' células.';
                }
            }

            return $problems;
        }

        if ($type === 'image') {
            $path = $block['path'] ?? null;

            if (! is_string($path) || ! is_file($path)) {
                // The renderer would die on a missing file with a Python
                // traceback the user can do nothing with. Catch it here, where
                // the message can name the slide.
                return ["{$where}: imagem não encontrada em disco."];
            }

            return [];
        }

        if (! in_array($type, ['bullet', 'paragraph'], true)) {
            return ["{$where}: tipo desconhecido (" . json_encode($type) . ').'];
        }

        if (blank($block['text'] ?? null)) {
            return ["{$where}: sem texto."];
        }

        $level = $block['level'] ?? 0;

        if (! is_int($level) || $level < 0 || $level > 3) {
            return ["{$where}: nível fora de 0-3."];
        }

        return [];
    }
}
