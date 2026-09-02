<?php

namespace App\Support\Documentation;

/**
 * Freezes the blocks the Documentation Assistant may neither write nor lose:
 * images, file cards, embeds and diagram citations.
 *
 * A reply rewrites the WHOLE page (the 4-backtick draft block), so everything
 * the model is not handed back comes out deleted. These five constructs are the
 * ones it cannot author correctly — `<figure><img src="/files/12">` needs a
 * media id only the upload knows, `{% diagram slug="…" %}` a slug from the
 * catalog — and the system prompt used to say so as a flat ban: "não use
 * imagens, `<figure>`, `<img>`, `{% file %}` nem `{% embed %}`".
 *
 * That ban is what deleted people's images. Told to return the complete page
 * and forbidden from writing a figure, the model resolved the contradiction the
 * only way it could: by dropping the figure that was already there — and it did
 * it while answering a request that had nothing to do with the image. Reported
 * from the app on 2026-08-31: "está removendo deliberadamente um objeto
 * <figure>, sendo que eu não pedi nada disso".
 *
 * So the model is given a marker instead of a rule to obey.
 * `[[BLOCK-1]]` is nine characters it can MOVE and cannot mangle, the prompt
 * says to keep every one of them, and the real bytes go back afterwards. Same
 * shape and same reasoning as `LiteralVault` (which freezes opaque literals)
 * and `SecretText` (which freezes protected values); the three are deliberately
 * separate vocabularies so a restore can never resolve the wrong kind.
 *
 * What this class does NOT do is put a dropped block back. A marker the model
 * deleted has no position left to restore it to, and guessing one would rewrite
 * somebody's page. It counts them instead — `dropped()` — so the reply can say
 * so out loud, which is the difference between a person being told and a person
 * noticing.
 */
final class BlockVault
{
    /** Deliberately ASCII and short: the model's one job with it is to copy it back byte for byte. */
    private const MARKER_FORMAT = '[[BLOCK-%d]]';

    /**
     * The constructs, and THE ORDER IS LOAD-BEARING.
     *
     * A `<figure>` contains an `<img>`, so the figure has to be captured first
     * or the image inside it is frozen on its own and the figure it belongs to
     * never matches anything again. `capture()` walks a copy in which each
     * captured block is already a marker, which is what makes the ordering
     * enough (the `<img>` pattern cannot see inside a figure that is no longer
     * there).
     *
     * @var array<int, array{0: string, 1: string}> [label, pattern]
     */
    private const PATTERNS = [
        ['imagem', '/<figure\b[^>]*>.*?<\/figure>/is'],
        ['imagem', '/<img\b[^>]*>/i'],
        ['arquivo', '/\{%\s*file\b[^%]*%\}/i'],
        ['vídeo/embed', '/\{%\s*embed\b[^%]*%\}/i'],
        ['diagrama', '/\{%\s*diagram\b[^%]*%\}/i'],
    ];

    /** @var array<string, string> marker => block */
    private array $blocks = [];

    /** @var array<string, string> marker => label, for the legend */
    private array $labels = [];

    private int $dropped = 0;

    /**
     * The same five constructs, REMOVED from a text rather than frozen in it.
     *
     * For context that is read but never rewritten: another documentation page
     * handed to the assistant as reference (`ContextPageResolver`). Freezing
     * those as `[[BLOCK-n]]` would be exactly wrong — a marker is an
     * instruction to keep the block, and these blocks belong to a DIFFERENT
     * page. Handing the raw markup over instead is worse: the model would be
     * shown a `/files/{id}` or a `{% diagram slug="…" %}` it is perfectly
     * capable of copying into the draft, which is how a reference page's image
     * ends up embedded in the page being written — and it would half-work,
     * since `MediaController` authorizes by collection while the magic link
     * scopes media to the caderno's own pages, so the same image renders inside
     * the app and breaks on the shared link.
     *
     * So the model is not shown the syntax at all. What it sees is a bracketed
     * PT-BR word — deliberately not a `[[…]]` marker, so no restore anywhere
     * can resolve it and nobody can mistake it for something to carry over.
     */
    public static function strip(?string $text): string
    {
        $text = (string) $text;

        if ($text === '') {
            return $text;
        }

        foreach (self::PATTERNS as [$label, $pattern]) {
            $text = (string) preg_replace($pattern, '[' . $label . ']', $text);
        }

        return $text;
    }

    /** @param list<string|null> $texts everything this turn will show the model */
    public static function from(array $texts): self
    {
        $vault = new self;

        foreach ($texts as $text) {
            $vault->capture((string) $text);
        }

        return $vault;
    }

    public function isEmpty(): bool
    {
        return $this->blocks === [];
    }

    /** Replaces every frozen block in $text with its marker. */
    public function mask(?string $text): string
    {
        $text = (string) $text;

        if ($text === '' || $this->isEmpty()) {
            return $text;
        }

        // Longest first, for the same reason LiteralVault sorts: one block can
        // contain another (an `<img>` that also appears standalone elsewhere),
        // and replacing the short one first would leave a marker embedded in a
        // block the long one no longer matches.
        $blocks = $this->blocks;
        uasort($blocks, fn (string $a, string $b) => strlen($b) <=> strlen($a));

        return str_replace(array_values($blocks), array_keys($blocks), $text);
    }

    public function restore(string $text): string
    {
        if ($this->isEmpty()) {
            return $text;
        }

        return str_replace(array_keys($this->blocks), array_values($this->blocks), $text);
    }

    /**
     * Counts the frozen blocks the draft did not bring back.
     *
     * Read-only, and it runs on the RAW draft — before any restore — because
     * that is the only text in which a marker is still a marker. It also has to
     * be the draft rather than the whole reply: a model that explains itself
     * ("removi o [[BLOCK-2]]") would otherwise be counted as having kept it,
     * which is exactly the turn this guard exists for.
     */
    public function audit(?string $draft): int
    {
        if ($this->isEmpty() || $draft === null) {
            return $this->dropped = 0;
        }

        $kept = 0;

        foreach (array_keys($this->blocks) as $marker) {
            if (str_contains($draft, $marker)) {
                $kept++;
            }
        }

        return $this->dropped = count($this->blocks) - $kept;
    }

    public function dropped(): int
    {
        return $this->dropped;
    }

    /**
     * What the person is told when a draft came back short.
     *
     * Deliberately does not accuse the model of a mistake: removing an image IS
     * legitimate when it was asked for. It states what the draft does not
     * contain and leaves the judgement where it belongs — with the person about
     * to press "Aplicar".
     */
    public function droppedNotice(): ?string
    {
        if ($this->dropped < 1) {
            return null;
        }

        return $this->dropped === 1
            ? '⚠️ Atenção: o rascunho não inclui 1 bloco que está na página (imagem, arquivo ou diagrama). '
                . 'Se você não pediu essa remoção, revise o rascunho antes de aplicar.'
            : "⚠️ Atenção: o rascunho não inclui {$this->dropped} blocos que estão na página (imagens, arquivos ou diagramas). "
                . 'Se você não pediu essa remoção, revise o rascunho antes de aplicar.';
    }

    /**
     * The markers, named well enough for the model to keep them in the right
     * places and to be told "tire a imagem do meio" — never well enough to
     * write one.
     */
    public function legend(): string
    {
        $lines = [];

        foreach ($this->labels as $marker => $label) {
            $lines[] = sprintf('- %s = %s', $marker, $label);
        }

        return implode("\n", $lines);
    }

    /** @return array{frozen: int, dropped: int} audit trail for the reply's meta */
    public function stats(): array
    {
        return [
            'frozen'  => count($this->blocks),
            'dropped' => $this->dropped,
        ];
    }

    /**
     * Registers every block in $text, working on a copy in which the ones
     * already registered are markers — see the note on PATTERNS.
     */
    private function capture(string $text): void
    {
        if ($text === '') {
            return;
        }

        foreach (self::PATTERNS as [$label, $pattern]) {
            $text = (string) preg_replace_callback(
                $pattern,
                fn (array $m): string => $this->register($m[0], $label),
                $text,
            );
        }
    }

    private function register(string $block, string $label): string
    {
        $existing = array_search($block, $this->blocks, true);

        if ($existing !== false) {
            return $existing;
        }

        $marker = sprintf(self::MARKER_FORMAT, count($this->blocks) + 1);

        $this->blocks[$marker] = $block;
        $this->labels[$marker] = $label;

        return $marker;
    }
}
