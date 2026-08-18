<?php

namespace App\Support\Cati;

/**
 * Turns a section's Markdown into the blocks a slide can hold.
 *
 * A slide is not a document: it has bullets, sub-bullets and tables, and
 * nothing else. This is the deterministic half of that translation — it never
 * shortens or rewrites, it only re-shapes. Condensing 300 words of prose into
 * something a committee can read from six metres away is a separate, model-
 * driven pass, and keeping the two apart is what makes this one testable.
 *
 * A GFM table becomes a NATIVE table block, which is how the "Modelo de
 * Operação", "Operação e resiliência" and "Plano de Implementação" slides get
 * their real PowerPoint tables instead of a wall of pipes.
 */
class MarkdownToBlocks
{
    /**
     * @return list<array{type: string, text?: string, level?: int, columns?: list<string>, rows?: list<list<string>>}>
     */
    public function convert(?string $markdown): array
    {
        $lines = preg_split('/\R/', (string) $markdown) ?: [];
        $blocks = [];
        $paragraph = [];
        $i = 0;
        $count = count($lines);

        while ($i < $count) {
            $line = $lines[$i];
            $trimmed = trim($line);

            // A table has to be recognised before anything else consumes its
            // header row as a paragraph.
            if ($this->looksLikeTableHeader($lines, $i)) {
                $this->flushParagraph($blocks, $paragraph);
                [$table, $i] = $this->readTable($lines, $i);
                $blocks[] = $table;

                continue;
            }

            if ($trimmed === '') {
                $this->flushParagraph($blocks, $paragraph);
                $i++;

                continue;
            }

            // A heading inside a section becomes a bullet of its own: the
            // slide's title is already taken by the section's name, so a
            // second title level would compete with it.
            if (preg_match('/^#{1,6}\s+(.*)$/', $trimmed, $m) === 1) {
                $this->flushParagraph($blocks, $paragraph);
                $blocks[] = ['type' => 'bullet', 'text' => $this->inline($m[1]), 'level' => 0];
                $i++;

                continue;
            }

            if (preg_match('/^(\s*)(?:[-*+]|\d+[.)])\s+(.*)$/', $line, $m) === 1) {
                $this->flushParagraph($blocks, $paragraph);
                $blocks[] = [
                    'type' => 'bullet',
                    'text' => $this->inline($m[2]),
                    // Two spaces per level, the same indentation the app's
                    // Markdown already documents. Capped: PowerPoint's own
                    // outline stops being readable past three levels.
                    'level' => min(3, intdiv(mb_strlen(str_replace("\t", '  ', $m[1])), 2)),
                ];
                $i++;

                continue;
            }

            if ($trimmed === '---' || $trimmed === '***') {
                $this->flushParagraph($blocks, $paragraph);
                $i++;

                continue;
            }

            $paragraph[] = $trimmed;
            $i++;
        }

        $this->flushParagraph($blocks, $paragraph);

        return $blocks;
    }

    /** @param  list<string>  $lines */
    private function looksLikeTableHeader(array $lines, int $i): bool
    {
        $header = trim($lines[$i] ?? '');
        $divider = trim($lines[$i + 1] ?? '');

        return str_starts_with($header, '|')
            && preg_match('/^\|[\s:|-]+\|$/', $divider) === 1
            && str_contains($divider, '-');
    }

    /**
     * @param  list<string>  $lines
     * @return array{0: array{type: string, columns: list<string>, rows: list<list<string>>}, 1: int}
     */
    private function readTable(array $lines, int $i): array
    {
        $columns = $this->cells($lines[$i]);
        $i += 2; // header + divider
        $rows = [];

        while ($i < count($lines) && str_starts_with(trim($lines[$i]), '|')) {
            $cells = $this->cells($lines[$i]);

            // Pad or trim to the header's width: a ragged row would otherwise
            // shift every cell after it into the wrong column.
            $cells = array_slice(array_pad($cells, count($columns), ''), 0, count($columns));
            $rows[] = $cells;
            $i++;
        }

        return [['type' => 'table', 'columns' => $columns, 'rows' => $rows], $i];
    }

    /** @return list<string> */
    private function cells(string $line): array
    {
        $line = trim(trim($line), '|');

        return array_map(fn (string $cell) => $this->inline(trim($cell)), explode('|', $line));
    }

    /**
     * Strips the inline Markdown a slide can't express.
     *
     * A placeholder's text inherits its run properties from the layout, so
     * bold/italic inside a run would mean emitting per-run formatting and
     * losing that inheritance. The marks come off rather than being rendered
     * as literal asterisks in front of the committee.
     */
    private function inline(string $text): string
    {
        $text = preg_replace('/\[([^\]]+)\]\([^)]+\)/', '$1', $text);       // [label](url)
        $text = preg_replace('/(\*\*|__)(.+?)\1/', '$2', $text);            // bold
        $text = preg_replace('/(?<!\*)\*(?!\*)(.+?)(?<!\*)\*(?!\*)/', '$1', $text); // italic
        $text = str_replace('`', '', $text);

        return trim($text);
    }

    /**
     * @param  list<array<string, mixed>>  $blocks
     * @param  list<string>  $paragraph
     */
    private function flushParagraph(array &$blocks, array &$paragraph): void
    {
        if ($paragraph === []) {
            return;
        }

        // Soft-wrapped lines are one paragraph, the same reading MarkdownText
        // gives these fields everywhere else in the app.
        $blocks[] = ['type' => 'paragraph', 'text' => implode(' ', $paragraph), 'level' => 0];
        $paragraph = [];
    }
}
