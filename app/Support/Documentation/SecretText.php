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
 * **A value is addressed by its ID**, stored on the construct itself:
 * `{% secret id="k3j9x2ab" %}…{% endsecret %}`. Three surfaces use it — the
 * RENDERER, which puts it on each lock; the REVEAL endpoint, which looks the
 * value up by it; and the EDITOR (and the assistant), which are shown
 * `[[SECRET-k3j9x2ab]]` in place of the value and get the real bytes put back
 * by `restore()` on save.
 *
 * It used to be the value's ORDINAL — 1 for the first `{% secret %}` in the
 * text — and that is what scrambled values in the field. The editor is handed
 * its markers once, when the page opens, and saves many times (autosave runs
 * ~1.2s after typing stops). Adding, deleting or moving a value renumbered the
 * page on the FIRST save, while the editor kept the numbers it was handed; every
 * save after that resolved stale numbers against the new order. Insert a value
 * above two others and three saves later the page held the new value three
 * times. An id does not move when the text around it does.
 *
 * **The id is RANDOM, never derived from the value**, and that is not
 * negotiable. An id computed from the value (a hash, even a keyed one) would be
 * an oracle: an editor who may not read a value could type a guess as a new
 * protected value, save, reload, and compare the two markers — once per save,
 * with no throttle. A random id says nothing about what it stands for.
 *
 * Markers that predate ids are numbers (`[[SECRET-2]]`) and are still resolved,
 * by ordinal — an editor tab left open across the deploy, or an assistant draft
 * written then, would otherwise turn every value on its page into a dead lock on
 * its next save. Ordinals are 1–7 digits and ids exactly eight characters, so
 * the two can never be confused.
 *
 * The marker shape is borrowed from App\Support\Documentation\LiteralVault: same
 * idea, one step further. The vault keeps a literal away from the language
 * model; this keeps it away from the PERSON editing the page, who may be an
 * editor rather than an admin — and from the assistant too, since the content
 * it is shown is masked before the vault ever sees it.
 */
final class SecretText
{
    /** Eight of `[a-z0-9]`: ~41 bits, and never the length of an ordinal. */
    public const ID_LENGTH = 8;

    private const ID_ALPHABET = 'abcdefghijklmnopqrstuvwxyz0123456789';

    /** Same shape as LiteralVault's `[[LIT-n]]`, deliberately — one marker vocabulary. */
    private const MARKER_FORMAT = '[[SECRET-%s]]';

    /** A marker's KEY: an id, or an ordinal from before ids existed. */
    private const MARKER = '\[\[SECRET-([a-z0-9]+)\]\]';

    /** A construct body that is one marker and nothing else. */
    private const SOLE_MARKER_PATTERN = '/^' . self::MARKER . '$/';

    private const ID_PATTERN = '/^[a-z0-9]{8}$/';

    private const ORDINAL_PATTERN = '/^[0-9]{1,7}$/';

    /**
     * The construct, and it is deliberately NOT multiline (`s` is absent).
     *
     * A protected value is a token, and a token has no newline in it. Letting
     * the body span lines would let an unclosed `{% secret %}` swallow the rest
     * of the page — silently, and into a lock nobody can open, since the
     * "value" would then be paragraphs of prose.
     *
     * The `id` attribute is optional and its value is matched loosely
     * (`[^"]*`), then validated by `validId()`. A construct with a malformed id
     * must still MATCH: one that didn't would render as ordinary text, value
     * and all.
     */
    private const CONSTRUCT = '\{%\s*secret(?:\s+id="([^"]*)")?\s*%\}(.*?)\{%\s*endsecret\s*%\}';

    private const CONSTRUCT_PATTERN = '/' . self::CONSTRUCT . '/';

    /** A construct, or a marker standing on its own — `restore()` reads both in one pass. */
    private const CONSTRUCT_OR_MARKER_PATTERN = '/' . self::CONSTRUCT . '|' . self::MARKER . '/';

    /**
     * Every protected value in `$markdown`, in document order, keyed from 1.
     *
     * @return array<int, string>
     */
    public static function values(?string $markdown): array
    {
        $values = [];

        foreach (self::entries($markdown) as $index => $entry) {
            $values[$index + 1] = $entry['value'];
        }

        return $values;
    }

    /**
     * The value a lock or a marker stands for, or null when this text has none.
     *
     * `$address` is an id — what every lock and marker carries — or an
     * ordinal, which only a page rendered before ids existed still asks for.
     */
    public static function valueOf(?string $markdown, string $address): ?string
    {
        return self::lookup(self::entries($markdown), $address)['value'] ?? null;
    }

    /**
     * The same text with every protected value replaced by its marker, the
     * construct itself left in place (and its id moved into the marker).
     *
     * What every reader of a page's TEXT is handed: the editor, the assistant,
     * "Copiar Markdown", the MCP tools. The construct stays so that saving the
     * page keeps the value protected: strip it and the marker becomes ordinary
     * prose.
     *
     * Idempotent — a construct already holding a marker keeps it — because the
     * assistant masks the EDITOR's content, which arrives masked already.
     *
     * A construct with neither an id nor a marker can only be text that never
     * went through a save (stored text always carries ids: `withIds()` runs on
     * every write of a page, and a migration gave the older rows theirs). It
     * gets a fresh id that `restore()` will not find, so it comes back as a
     * lock nobody can open — a visible failure, where any number it could be
     * given might resolve to somebody else's value.
     */
    public static function mask(?string $markdown): string
    {
        if (blank($markdown)) {
            return (string) $markdown;
        }

        $taken = self::takenIds($markdown);

        return (string) preg_replace_callback(
            self::CONSTRUCT_PATTERN,
            function (array $m) use (&$taken): string {
                $key = self::validId($m[1])
                    ?? self::soleMarkerKey($m[2])
                    ?? self::freshId($taken);

                return '{% secret %}' . sprintf(self::MARKER_FORMAT, $key) . '{% endsecret %}';
            },
            $markdown,
        );
    }

    /**
     * Puts the real values back into `$incoming`, reading them from `$stored` —
     * the version of the page that is still in the database.
     *
     * @see restoreWithIds()
     */
    public static function restore(?string $incoming, ?string $stored): string
    {
        return self::restoreWithIds($incoming, $stored)[0];
    }

    /**
     * Puts the real values back into `$incoming` and gives every construct the
     * id it is stored under. Also returns those ids, one per `{% secret %}` in
     * `$incoming`, in order: the editor uses them to swap a value somebody just
     * typed for its marker, so that what it holds after a save is markers only.
     *
     * Runs on EVERY save, whoever made it and whether or not they were shown
     * markers: the assistant's draft is always built from masked content, so
     * an admin who applies a draft is saving markers too.
     *
     * A marker is resolved by ID, wherever it ended up: the model may reorder
     * the page and a person may cut a sentence and paste it lower down. A
     * marker this page has no value for (deleted meanwhile, or invented by the
     * model) is kept, as the literal body of a fresh construct — it renders as
     * a lock that opens onto the marker, which is a visible bug, where silently
     * dropping it or substituting the nearest value would not be.
     *
     * **An id the client sends is never trusted.** A construct whose body is
     * not exactly a marker is a value somebody typed (or typed next to), and
     * it gets a fresh id whatever its `id` attribute says — honouring a posted
     * id would let anyone who can save the page point an existing lock at a
     * value of their choosing.
     *
     * Deleting the marker deletes the value, on purpose: removing a protected
     * value from a page has to be possible for whoever is writing the page, and
     * the marker is the only thing standing where it used to be.
     *
     * **A marker that lost its construct is RE-WRAPPED, never resolved into
     * naked plaintext.** That is the guard that makes this feature's protection
     * the server's rather than the client's, and it is not hypothetical: a
     * protected value inside inline code round-tripped through the editor as
     * `` `[[SECRET-2]]` `` — the wrapper flattened away by the serializer — and
     * a plain resolve would have written the real value into the page in the
     * clear, with the editor showing a lock chip right up until the save. No
     * author ever types a marker by hand, so a bare marker can only mean "the
     * protected value that was here", and the construct is part of what it
     * means.
     *
     * Constructs and bare markers are read in ONE pass, so a marker inside a
     * value that was just put back is never read a second time (a value can be
     * the literal text of a marker: the dead lock above).
     *
     * @return array{0: string, 1: list<string>}
     */
    public static function restoreWithIds(?string $incoming, ?string $stored): array
    {
        $incoming = (string) $incoming;

        if ($incoming === '') {
            return ['', []];
        }

        $entries = self::entries($stored);
        $taken = self::takenIds($stored) + self::takenIds($incoming);
        $ids = [];

        $markdown = (string) preg_replace_callback(
            self::CONSTRUCT_OR_MARKER_PATTERN,
            function (array $m) use ($entries, &$taken, &$ids): string {
                // A marker standing on its own: re-wrapped when it resolves,
                // left as text when it doesn't.
                if ($m[3] !== null) {
                    $hit = self::lookup($entries, $m[3]);

                    return $hit === null
                        ? $m[0]
                        : self::construct($hit['id'] ?? self::freshId($taken), $hit['value']);
                }

                $key = self::soleMarkerKey($m[2]);
                $hit = $key === null ? null : self::lookup($entries, $key);

                [$id, $value] = $hit !== null
                    ? [$hit['id'] ?? self::freshId($taken), $hit['value']]
                    : [self::freshId($taken), self::resolveMarkers($m[2], $entries)];

                $ids[] = $id;

                return self::construct($id, $value);
            },
            $incoming,
            flags: PREG_UNMATCHED_AS_NULL,
        );

        return [$markdown, $ids];
    }

    /**
     * Gives every construct that lacks a valid id a fresh one; returns the text
     * unchanged when they all have one.
     *
     * The invariant behind `mask()` — every stored construct carries its id —
     * and therefore applied to every write of a page
     * (`DocumentationPage::booted()`), not only to the editor's saves. Only for
     * text the SERVER trusts: an id posted by a client goes through
     * `restore()`, which never honours it.
     */
    public static function withIds(?string $markdown): ?string
    {
        if (blank($markdown)) {
            return $markdown;
        }

        $taken = self::takenIds($markdown);

        return (string) preg_replace_callback(
            self::CONSTRUCT_PATTERN,
            fn (array $m): string => self::validId($m[1]) !== null
                ? $m[0]
                : self::construct(self::freshId($taken), $m[2]),
            $markdown,
        );
    }

    /**
     * Replaces each construct with `$replace($address)` — how the renderer
     * paints its locks without this class knowing anything about HTML.
     *
     * The address is the construct's id; a construct with none (text that was
     * never saved, like a model's reply) is addressed by its ordinal instead.
     *
     * @param  callable(string): string  $replace
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
            // fresh copy on every match and number every value 1.
            function (array $m) use (&$ordinal, $replace): string {
                $ordinal++;

                return $replace(self::validId($m[1]) ?? (string) $ordinal);
            },
            $markdown,
        );
    }

    /**
     * Every construct in `$markdown`, in document order.
     *
     * @return list<array{id: ?string, value: string}>
     */
    private static function entries(?string $markdown): array
    {
        if (blank($markdown) || preg_match_all(self::CONSTRUCT_PATTERN, $markdown, $matches, PREG_SET_ORDER | PREG_UNMATCHED_AS_NULL) === 0) {
            return [];
        }

        return array_map(
            fn (array $m): array => ['id' => self::validId($m[1]), 'value' => (string) $m[2]],
            $matches,
        );
    }

    /**
     * The entry an id or an ordinal points at. An id that two constructs
     * share (a marker copied within the page) holds the same value in both.
     *
     * @param  list<array{id: ?string, value: string}>  $entries
     * @return array{id: ?string, value: string}|null
     */
    private static function lookup(array $entries, string $address): ?array
    {
        if (preg_match(self::ID_PATTERN, $address) === 1) {
            foreach ($entries as $entry) {
                if ($entry['id'] === $address) {
                    return $entry;
                }
            }

            return null;
        }

        if (preg_match(self::ORDINAL_PATTERN, $address) === 1) {
            return $entries[(int) $address - 1] ?? null;
        }

        return null;
    }

    /**
     * Resolves every marker inside a body that is more than one marker — a
     * value somebody typed something next to — leaving the ones that don't
     * resolve as they are.
     *
     * @param  list<array{id: ?string, value: string}>  $entries
     */
    private static function resolveMarkers(string $body, array $entries): string
    {
        return (string) preg_replace_callback(
            '/' . self::MARKER . '/',
            fn (array $m): string => self::lookup($entries, $m[1])['value'] ?? $m[0],
            $body,
        );
    }

    private static function soleMarkerKey(?string $body): ?string
    {
        return preg_match(self::SOLE_MARKER_PATTERN, (string) $body, $m) === 1 ? $m[1] : null;
    }

    private static function validId(?string $id): ?string
    {
        return $id !== null && preg_match(self::ID_PATTERN, $id) === 1 ? $id : null;
    }

    /**
     * Ids already in use in `$markdown` — on constructs and in markers alike,
     * so a fresh id can never collide with one that resolves to another value.
     *
     * @return array<string, true>
     */
    private static function takenIds(?string $markdown): array
    {
        preg_match_all('/id="([a-z0-9]{8})"|\[\[SECRET-([a-z0-9]{8})\]\]/', (string) $markdown, $matches);

        return array_fill_keys(array_filter([...$matches[1], ...$matches[2]]), true);
    }

    /** @param  array<string, true>  $taken */
    private static function freshId(array &$taken): string
    {
        do {
            $id = '';

            for ($i = 0; $i < self::ID_LENGTH; $i++) {
                $id .= self::ID_ALPHABET[random_int(0, strlen(self::ID_ALPHABET) - 1)];
            }
        } while (isset($taken[$id]));

        $taken[$id] = true;

        return $id;
    }

    private static function construct(string $id, string $value): string
    {
        return '{% secret id="' . $id . '" %}' . $value . '{% endsecret %}';
    }
}
