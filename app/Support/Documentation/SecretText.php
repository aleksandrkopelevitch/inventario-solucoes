<?php

namespace App\Support\Documentation;

/**
 * Protected values inside a documentation page: `{% secret %}` … `{% endsecret %}`.
 *
 * The fifth construct of the dialect after `hint`/`tabs`/`file`/`diagram`, and
 * the only INLINE one — a protected value is a token in the middle of a
 * sentence, a cell of a table or a line of a code fence, never a block of its
 * own. It is written by the editor's "Valor protegido" inline tool
 * (resources/js/modules/docs-tools/secret.js) and read by this class.
 *
 * **The value never reaches the reader's browser.** `GitbookRenderer` replaces
 * it with a lock, and the plaintext is handed out one value at a time by
 * App\Actions\Documentation\RevealPageSecret, to an admin or to whoever types
 * the caderno's secret code (`Notebook::secret_code`). That is the whole reason
 * this is a server-side construct and not a `display: none` span: markup that
 * ships the value and hides it with CSS is not protection, it is a blindfold —
 * and the five-attempt limit in front of it would be guarding a door with the
 * key already taped to the other side.
 *
 * Everything here addresses a value by its ORDINAL — 1 for the first
 * `{% secret %}` in the page's text, in document order — because that is the
 * only identifier a value has. It lives inline in the Markdown, so there is no
 * row to give it an id, and adding one would mean a second place where a page's
 * content lives.
 *
 * Three surfaces read the ordinal and have to agree on it:
 *
 * - the RENDERER, which numbers the locks it paints;
 * - the REVEAL endpoint, which re-parses the page's current text and returns
 *   the Nth value;
 * - the EDITOR, which shows `[[SECRET-n]]` in place of a value to anyone who
 *   may not read it, and gets the real bytes put back by `restore()` on save.
 *
 * That third one is why the marker shape is borrowed from
 * App\Support\Documentation\LiteralVault: same idea, one step further. The vault
 * keeps a literal away from the language model; this keeps it away from the
 * PERSON editing the page, who may be an editor rather than an admin — and from
 * the assistant too, since the content it is shown is masked before the vault
 * ever sees it.
 *
 * A stale render is the accepted cost of numbering by position: someone reading
 * a page while somebody else adds a secret above it holds a page whose locks
 * are numbered against text that has changed, and revealing gives them the
 * wrong value of the two. The window is one page load, both values are in the
 * same caderno, and the alternative is a table.
 */
final class SecretText
{
    /** Same shape as LiteralVault's `[[LIT-n]]`, deliberately — one marker vocabulary. */
    private const MARKER_FORMAT = '[[SECRET-%d]]';

    private const MARKER_PATTERN = '/\[\[SECRET-(\d+)\]\]/';

    /**
     * The construct, and it is deliberately NOT multiline (`s` is absent).
     *
     * A protected value is a token, and a token has no newline in it. Letting
     * the body span lines would let an unclosed `{% secret %}` swallow the rest
     * of the page — silently, and into a lock nobody can open, since the
     * "value" would then be paragraphs of prose.
     */
    private const CONSTRUCT_PATTERN = '/\{%\s*secret\s*%\}(.*?)\{%\s*endsecret\s*%\}/';

    /**
     * Every protected value in `$markdown`, keyed by its 1-based ordinal.
     *
     * @return array<int, string>
     */
    public static function values(?string $markdown): array
    {
        if (blank($markdown) || preg_match_all(self::CONSTRUCT_PATTERN, $markdown, $matches) === 0) {
            return [];
        }

        $values = [];

        foreach ($matches[1] as $index => $value) {
            $values[$index + 1] = $value;
        }

        return $values;
    }

    /** The Nth protected value, or null when the page has no such ordinal. */
    public static function valueAt(?string $markdown, int $ordinal): ?string
    {
        return self::values($markdown)[$ordinal] ?? null;
    }

    public static function count(?string $markdown): int
    {
        return count(self::values($markdown));
    }

    /**
     * The same text with every protected value replaced by its marker, the
     * construct itself left in place.
     *
     * What the editor is fed for a reader who may not see the values, and what
     * the Documentation Assistant is shown in every case. The construct stays
     * so that saving the page keeps the value protected: strip it and the
     * marker becomes ordinary prose, and the next save would write
     * `[[SECRET-1]]` into the page as literal text.
     */
    public static function mask(?string $markdown): string
    {
        if (blank($markdown)) {
            return (string) $markdown;
        }

        $ordinal = 0;

        return (string) preg_replace_callback(
            self::CONSTRUCT_PATTERN,
            function () use (&$ordinal): string {
                return '{% secret %}' . sprintf(self::MARKER_FORMAT, ++$ordinal) . '{% endsecret %}';
            },
            $markdown,
        );
    }

    /**
     * Puts the real values back into `$incoming`, reading them from `$stored` —
     * the version of the page that is still in the database.
     *
     * Runs on EVERY save, whoever made it and whether or not they were shown
     * markers: an admin edits real values, but the assistant's draft is always
     * built from masked content, so an admin who applies a draft is saving
     * markers too.
     *
     * By NUMBER, not by position. The model may reorder the page, and a person
     * may cut a sentence and paste it lower down; a marker means "the value that
     * was second when this text was handed out", wherever it ended up. A marker
     * whose ordinal no longer exists (the page had two values, the draft invents
     * `[[SECRET-7]]`) is left exactly as it is rather than guessed at — it
     * renders as a lock nobody can open, which is a visible bug, where silently
     * dropping it or substituting the nearest value would not be.
     *
     * Deleting the marker deletes the value, on purpose: removing a protected
     * value from a page has to be possible for whoever is writing the page, and
     * the marker is the only thing standing where it used to be.
     */
    public static function restore(?string $incoming, ?string $stored): string
    {
        $incoming = (string) $incoming;
        $values = self::values($stored);

        if ($incoming === '' || $values === []) {
            return $incoming;
        }

        return (string) preg_replace_callback(
            self::MARKER_PATTERN,
            fn (array $m): string => $values[(int) $m[1]] ?? $m[0],
            $incoming,
        );
    }

    /**
     * Replaces each construct with `$replace($ordinal)` — how the renderer
     * paints its locks without this class knowing anything about HTML.
     *
     * @param  callable(int): string  $replace
     */
    public static function replace(?string $markdown, callable $replace): string
    {
        if (blank($markdown)) {
            return (string) $markdown;
        }

        $ordinal = 0;

        return (string) preg_replace_callback(
            self::CONSTRUCT_PATTERN,
            // A closure with `use (&$ordinal)`, NOT an arrow function: an arrow
            // function captures by VALUE, so `++$ordinal` would increment a
            // fresh copy on every match and number every value 1 — which is
            // both the wrong lock and, once one is revealed, the wrong VALUE.
            function () use (&$ordinal, $replace): string {
                return $replace(++$ordinal);
            },
            $markdown,
        );
    }
}
