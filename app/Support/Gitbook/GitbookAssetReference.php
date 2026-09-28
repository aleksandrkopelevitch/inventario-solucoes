<?php

namespace App\Support\Gitbook;

/**
 * The one definition of "this bit of Markdown points at an asset".
 *
 * It was inlined in `GitbookAssetImporter::rehost()` while the importer was the
 * only thing that needed it. `gitbook:archive` needs exactly the same answer —
 * it has to download precisely what a later restore will look for, or the
 * archive is quietly incomplete in the way nobody discovers until GitBook is
 * gone — so the pattern lives here and both read it.
 *
 * Three shapes, and each is in the list for a reason written up in
 * `GitbookAssetImporter`'s docblock:
 *
 * - `<img src="…">`, the normalised image form;
 * - `{% file src="…" %}`, the attachment block;
 * - `<a href="/files/…">`, a document LINKED from prose or a table cell.
 *   Scoped to a `/files/` or `/spaces/…/files/` href specifically — an
 *   ordinary outbound link is not an embedded asset, and "re-hosting" every
 *   hyperlink in the corpus would be wrong rather than thorough.
 */
final class GitbookAssetReference
{
    public const PATTERN = '/(<img[^>]*\ssrc=")([^"]+)(")'
        . '|(\{%\s*file\s+src=")([^"]+)("\s*%\})'
        . '|(<a[^>]*\shref=")((?:\/files\/|\/spaces\/[A-Za-z0-9]+\/files\/)[^"]+)(")/i';

    /**
     * The three captured pieces of one match, whichever alternative fired.
     *
     * @param  array<int, string>  $matches
     * @return array{0: string, 1: string, 2: string} [prefix, reference, suffix]
     */
    public static function parts(array $matches): array
    {
        [$prefix, $reference, $suffix] = match (true) {
            ($matches[2] ?? '') !== '' => [$matches[1], $matches[2], $matches[3]],
            ($matches[5] ?? '') !== '' => [$matches[4], $matches[5], $matches[6]],
            default                    => [$matches[7], $matches[8], $matches[9]],
        };

        return [$prefix, html_entity_decode($reference, ENT_QUOTES), $suffix];
    }

    /**
     * Every distinct reference in one page's Markdown, in order of appearance.
     *
     * @return array<int, string>
     */
    public static function all(string $markdown): array
    {
        if (! preg_match_all(self::PATTERN, $markdown, $matches, PREG_SET_ORDER)) {
            return [];
        }

        $references = [];

        foreach ($matches as $match) {
            // A short alternative leaves the later groups unset rather than
            // empty, and `parts()` reads them positionally.
            $references[] = self::parts($match + array_fill(0, 10, ''))[1];
        }

        return array_values(array_unique($references));
    }
}
