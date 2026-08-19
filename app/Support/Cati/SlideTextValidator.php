<?php

namespace App\Support\Cati;

/**
 * Checks that a condensed section is actually slide-sized.
 *
 * Runs over the BLOCKS, not the Markdown, so it judges what will really be
 * placed — and it is the same `MarkdownToBlocks` output the deterministic path
 * produces, so both roads answer to one definition of "fits".
 *
 * These bounds are deliberately tighter than DeckSpecValidator's. That one
 * guards against a deck the renderer cannot place; this one guards against a
 * deck nobody can read from the back of the room, which is the whole reason
 * the condensation pass exists.
 */
class SlideTextValidator
{
    private const MAX_LINES = 6;

    private const MAX_LINE_CHARS = 120;

    private const MAX_LEVEL = 1;

    /**
     * @param  list<array<string, mixed>>  $blocks
     * @return list<string> problems (empty = fits)
     */
    public function validate(array $blocks): array
    {
        if ($blocks === []) {
            return ['veio vazio'];
        }

        $problems = [];
        $lines = 0;

        foreach ($blocks as $block) {
            if (($block['type'] ?? null) === 'table') {
                // A table is the slide; the line budget doesn't apply to it.
                $columns = count($block['columns'] ?? []);
                $rows = count($block['rows'] ?? []);

                if ($columns > 6) {
                    $problems[] = "tabela com {$columns} colunas (máximo 6)";
                }

                if ($rows > 12) {
                    $problems[] = "tabela com {$rows} linhas (máximo 12)";
                }

                continue;
            }

            $lines++;
            $text = (string) ($block['text'] ?? '');

            if (mb_strlen($text) > self::MAX_LINE_CHARS) {
                $problems[] = 'linha com ' . mb_strlen($text) . ' caracteres (máximo ' . self::MAX_LINE_CHARS . '): "'
                    . mb_substr($text, 0, 40) . '…"';
            }

            if ((int) ($block['level'] ?? 0) > self::MAX_LEVEL) {
                $problems[] = 'subitem aninhado além de um nível';
            }
        }

        if ($lines > self::MAX_LINES) {
            $problems[] = "{$lines} linhas (máximo " . self::MAX_LINES . ')';
        }

        return array_values(array_unique($problems));
    }
}
