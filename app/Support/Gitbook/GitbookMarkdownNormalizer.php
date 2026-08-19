<?php

namespace App\Support\Gitbook;

/**
 * Bends GitBook's Markdown into the exact dialect this app reads.
 *
 * The two dialects overlap almost completely — that is what makes the import
 * cheap — but "almost" hides three real gaps, and all three fail *silently*
 * (the page saves, and the construct simply shows up as literal `{% … %}`
 * text on screen, or vanishes from the editor):
 *
 * 1. **Constructs this app never learned.** `{% content-ref %}`,
 *    `{% stepper %}`, `{% columns %}`, `{% code %}` are not among the four
 *    App\Support\GitbookRenderer handles, so they would render as raw text.
 *    Each is down-converted to something honest: a content-ref becomes the
 *    link it always was, a code block loses its wrapper and keeps its fence,
 *    and purely structural wrappers (stepper/step, columns/column) are
 *    unwrapped, keeping their content in order.
 * 2. **Attribute-strict tags.** The four supported constructs are matched by
 *    regexes that accept ONE attribute and nothing more — resources/js/modules/
 *    docs-markdown.js reads `^\{%\s*embed\s+url="([^"]*)"\s*%\}$`. GitBook
 *    happily writes `{% embed url="…" fullWidth="false" %}`, which matches
 *    neither that nor GitbookRenderer's twin regex. The extra attributes are
 *    stripped, which is the difference between an embed block and a line of
 *    visible punctuation.
 * 3. **Images.** The editor recognises an image only as a `<figure>`/`<img>`
 *    at the START of a line (docs-markdown.js `parseLines`), never as
 *    `![alt](url)`. GitBook writes both, and writes multi-line figures with a
 *    `<figcaption><p>…</p></figcaption>` this app's single-line figure parser
 *    cannot read. Everything image-shaped is normalised to the one form both
 *    sides agree on.
 *
 * What can't be down-converted honestly is replaced by a visible PT-BR
 * callout naming what was left behind — reusable content (`{% include %}`)
 * and API-reference blocks (`{% openapi %}`) live outside the space's own
 * Markdown, so there is nothing here to convert. Better a note the reader
 * sees than a hole nobody notices.
 *
 * URLs are NOT touched here: assets are still remote at this point.
 * GitbookAssetImporter re-hosts them afterwards and rewrites the src it finds
 * in exactly the shapes this class guarantees.
 */
class GitbookMarkdownNormalizer
{
    /** Supported by GitbookRenderer + docs-markdown.js — kept, attributes trimmed to the one each parser accepts. */
    private const KEPT = ['hint', 'tabs', 'tab', 'file', 'embed'];

    /** Pure structure in GitBook, nothing to represent here: drop the tag, keep what's inside. */
    private const UNWRAPPED = ['stepper', 'step', 'columns', 'column', 'cards', 'card'];

    /** Content that lives outside this Markdown — replaced by a callout saying so. */
    private const UNRESOLVABLE = [
        'include'           => 'Conteúdo reutilizável do GitBook',
        'openapi'           => 'Bloco de referência de API (OpenAPI) do GitBook',
        'openapi-operation' => 'Bloco de referência de API (OpenAPI) do GitBook',
        'openapi-schemas'   => 'Bloco de referência de API (OpenAPI) do GitBook',
        'swagger'           => 'Bloco de referência de API (Swagger) do GitBook',
    ];

    public function normalize(?string $markdown): string
    {
        if (blank($markdown)) {
            return '';
        }

        $lines = preg_split('/\r\n|\r|\n/', $markdown) ?: [];

        return $this->tidy($this->walk($lines));
    }

    /**
     * @param  array<int, string>  $lines
     * @return array<int, string>
     */
    private function walk(array $lines): array
    {
        $out = [];
        $i = 0;
        $n = count($lines);

        while ($i < $n) {
            $line = $lines[$i];
            $trimmed = trim($line);

            // A fence is verbatim territory — a `{% %}` or an `![](…)` inside
            // one is sample text, not notation to rewrite.
            if (preg_match('/^(```|~~~)/', $trimmed, $m)) {
                $out[] = $line;
                $i++;
                while ($i < $n && ! str_starts_with(trim($lines[$i]), $m[1])) {
                    $out[] = $lines[$i];
                    $i++;
                }
                if ($i < $n) {
                    $out[] = $lines[$i];
                    $i++;
                }

                continue;
            }

            // A figure may span several lines; collapse the whole element.
            if (str_starts_with($trimmed, '<figure')) {
                $collected = [];
                while ($i < $n) {
                    $collected[] = $lines[$i];
                    $i++;
                    if (str_contains(end($collected), '</figure>')) {
                        break;
                    }
                }
                $out[] = $this->figure(implode(' ', $collected));

                continue;
            }

            if ($trimmed !== '' && preg_match('/^\{%\s*([\w-]+)\s*(.*?)\s*%\}$/', $trimmed, $m)) {
                [$tag, $attrs] = [strtolower($m[1]), $m[2]];
                [$emitted, $i] = $this->tag($tag, $attrs, $lines, $i);
                $out = [...$out, ...$emitted];

                continue;
            }

            $out[] = $this->image($line);
            $i++;
        }

        return $out;
    }

    /**
     * Handles one `{% … %}` line, returning what to emit and where to resume
     * (past the whole block, for the tags whose content is dropped).
     *
     * @param  array<int, string>  $lines
     * @return array{0: array<int, string>, 1: int}
     */
    private function tag(string $tag, string $attrs, array $lines, int $i): array
    {
        $closing = str_starts_with($tag, 'end');
        $bare = $closing ? substr($tag, 3) : $tag;

        if (in_array($bare, self::KEPT, true)) {
            return [[$closing ? '{% end' . $bare . ' %}' : $this->keptTag($bare, $attrs)], $i + 1];
        }

        // Structure only: the tag goes, the content stays. Emitting a blank
        // line in its place is what keeps two steps from merging into one
        // paragraph once the wrappers are gone.
        if (in_array($bare, self::UNWRAPPED, true)) {
            return [[''], $i + 1];
        }

        if ($bare === 'content-ref') {
            return $this->contentRef($attrs, $lines, $i);
        }

        if ($bare === 'code') {
            // The fence inside is already valid Markdown; only the title is
            // worth keeping, as the line of context it was.
            $title = $this->attr($attrs, 'title');

            return [$closing || $title === '' ? [''] : ['**' . $title . '**', ''], $i + 1];
        }

        if (isset(self::UNRESOLVABLE[$bare])) {
            return $closing
                ? [[''], $i + 1]
                : $this->unresolvable($bare, $attrs, $lines, $i);
        }

        // Anything unrecognised: unwrap it if it's a pair, drop the line if
        // it's standalone. Never leave `{% … %}` on the page.
        return [[''], $i + 1];
    }

    /** Re-emits a supported tag with only the one attribute its parser accepts. */
    private function keptTag(string $tag, string $attrs): string
    {
        return match ($tag) {
            // Style is mandatory in both parsers' regexes; `icon` is the one
            // extra attribute docs-markdown.js knows about.
            'hint' => '{% hint style="' . ($this->attr($attrs, 'style') ?: 'info') . '"'
                . (($icon = $this->attr($attrs, 'icon')) !== '' ? ' icon="' . $icon . '"' : '')
                . ' %}',
            'tab'   => '{% tab title="' . $this->attr($attrs, 'title') . '" %}',
            'file'  => '{% file src="' . $this->attr($attrs, 'src') . '" %}',
            'embed' => '{% embed url="' . $this->attr($attrs, 'url') . '" %}',
            default => '{% tabs %}',
        };
    }

    /**
     * `{% content-ref url="X" %}[Título](path){% endcontent-ref %}` is a card
     * linking to another page — it was always just a link, so it becomes one.
     * The inner Markdown link is the better label when it's there.
     *
     * @param  array<int, string>  $lines
     * @return array{0: array<int, string>, 1: int}
     */
    private function contentRef(string $attrs, array $lines, int $i): array
    {
        $url = $this->attr($attrs, 'url');
        [$inner, $next] = $this->consume($lines, $i + 1, 'content-ref');
        $body = trim(implode(' ', $inner));

        if (preg_match('/\[([^\]]*)\]\(([^)]*)\)/', $body, $m)) {
            return [['[' . ($m[1] ?: $url) . '](' . ($m[2] ?: $url) . ')'], $next];
        }

        return [$url === '' ? [''] : ['[' . ($body ?: $url) . '](' . $url . ')'], $next];
    }

    /**
     * @param  array<int, string>  $lines
     * @return array{0: array<int, string>, 1: int}
     */
    private function unresolvable(string $tag, string $attrs, array $lines, int $i): array
    {
        $reference = $this->attr($attrs, 'src')
            ?: $this->attr($attrs, 'url')
            ?: trim($attrs, " \t\"'");

        // Paired form (older `{% swagger %}` blocks): the body goes with it.
        [, $next] = $this->consume($lines, $i + 1, $tag);

        $note = self::UNRESOLVABLE[$tag] . ' não importado'
            . ($reference !== '' ? ': ' . $reference : '')
            . '. O conteúdo original está no GitBook.';

        return [['{% hint style="warning" %}', $note, '{% endhint %}', ''], $next];
    }

    /**
     * Lines up to the matching `{% end$tag %}`. Returns the lines and the
     * index to resume at — which is `$i` itself when there is no closer, so a
     * standalone tag consumes nothing.
     *
     * @param  array<int, string>  $lines
     * @return array{0: array<int, string>, 1: int}
     */
    private function consume(array $lines, int $i, string $tag): array
    {
        $close = '/^\{%\s*end' . preg_quote($tag, '/') . '\s*%\}$/';
        $inner = [];
        $n = count($lines);
        $j = $i;

        while ($j < $n) {
            if (preg_match($close, trim($lines[$j]))) {
                return [$inner, $j + 1];
            }
            $inner[] = $lines[$j];
            $j++;
        }

        return [[], $i];
    }

    /**
     * Collapses a whole `<figure>` element to the single line
     * docs-markdown.js's `parseLines` can read, dropping GitBook's pixel
     * `width` (this app sizes figures with `data-width` percentages) and
     * unwrapping the `<p>` GitBook nests inside `<figcaption>`.
     */
    private function figure(string $html): string
    {
        preg_match('/<img[^>]*\ssrc="([^"]*)"/i', $html, $src);
        preg_match('/<img[^>]*\salt="([^"]*)"/i', $html, $alt);
        preg_match('#<figcaption>(.*?)</figcaption>#is', $html, $caption);

        $text = trim(html_entity_decode(strip_tags($caption[1] ?? ''), ENT_QUOTES));

        return '<figure><img src="' . ($src[1] ?? '') . '" alt="' . ($alt[1] ?? '') . '">'
            . '<figcaption>' . $text . '</figcaption></figure>';
    }

    /**
     * A standalone `![alt](url)` becomes a figure — the editor only ever
     * recognises an image block from `<figure>`/`<img>`. An image sitting
     * INSIDE a sentence is left alone: it renders correctly either way, and
     * lifting it out would break the paragraph around it.
     */
    private function image(string $line): string
    {
        $trimmed = trim($line);

        if (! preg_match('/^!\[([^\]]*)\]\(\s*<?([^)>]*)>?\s*(?:"[^"]*")?\s*\)$/', $trimmed, $m)) {
            return $line;
        }

        return '<figure><img src="' . trim($m[2]) . '" alt="' . $m[1] . '">'
            . '<figcaption>' . $m[1] . '</figcaption></figure>';
    }

    /** One `key="value"` out of a GitBook attribute string (order is free). */
    private function attr(string $raw, string $key): string
    {
        return preg_match('/\b' . preg_quote($key, '/') . '="([^"]*)"/', $raw, $m) ? $m[1] : '';
    }

    /**
     * @param  array<int, string>  $lines
     */
    private function tidy(array $lines): string
    {
        $text = implode("\n", $lines);
        // Unwrapping wrappers leaves runs of blank lines behind; one blank
        // line is a paragraph break, more is just noise in the editor.
        $text = preg_replace("/\n{3,}/", "\n\n", $text) ?? $text;

        return trim($text);
    }
}
