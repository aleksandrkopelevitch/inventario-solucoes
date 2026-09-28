<?php

namespace App\Support\Gitbook;

/**
 * The layout of a `.zip` written by `gitbook:archive`, named in ONE place so
 * the writer and the reader cannot drift.
 *
 * ```
 * Integracoes-Digibee--edu5p1Zks7fwfqwdtWhR.zip
 * ├── manifest.json        format, space id/title, when, counts
 * ├── space.json           GET /spaces/{id}                       (verbatim)
 * ├── pages.json           GET /spaces/{id}/content/pages         (verbatim)
 * ├── pages/{pageId}.md    GET .../content/page/{id}?format=markdown
 * └── assets/
 *     ├── index.json       reference-as-written → {file, name}
 *     └── 3f1c…a9.png      the bytes
 * ```
 *
 * **It stores GitBook's answers, not this app's reading of them.** The Markdown
 * is the raw GitBook dialect, before `GitbookMarkdownNormalizer`, and the asset
 * references are untouched. Two reasons, and the first is the one that matters:
 *
 * - the archive is a backup of GITBOOK, so it has to survive this app changing
 *   its mind. Every fix the normalizer has ever needed was found against real
 *   pages, and an archive of pre-normalized text could never benefit from the
 *   next one — it would freeze today's bugs as the record;
 * - and it makes a restore replay the actual import (`ImportGitbookSpace`
 *   handed a `GitbookArchive` instead of a `GitbookClient`) rather than being a
 *   second, subtly different one.
 *
 * The exception is `assets/index.json`, which IS resolved at archive time and
 * on purpose: a reference can point at another space's file list or at an
 * absolute CDN URL, and resolving those needs the network. Doing it while the
 * network is still there is the difference between an archive that restores
 * offline and one that only looks like it does.
 */
final class GitbookArchiveFormat
{
    /**
     * Bumped when an archive written by an older version can no longer be read.
     * `GitbookArchive` refuses a version it does not know rather than guessing
     * — a backup that half-restores is worse than one that says it cannot.
     */
    public const VERSION = 1;

    public const MANIFEST = 'manifest.json';

    public const SPACE = 'space.json';

    public const PAGES = 'pages.json';

    public const PAGE_DIR = 'pages/';

    public const ASSET_DIR = 'assets/';

    public const ASSET_INDEX = 'assets/index.json';

    /** Where a page's Markdown lives, by its GitBook node id. */
    public static function pagePath(string $pageId): string
    {
        return self::PAGE_DIR . self::safe($pageId) . '.md';
    }

    /**
     * An asset's file name inside the archive: the reference hashed, plus the
     * extension its name suggests.
     *
     * Hashed rather than named after the file, because the same space really
     * does hold several "image.png" and a GitBook display name is free text
     * (slashes, accents, 200 characters). The extension is kept anyway — an
     * archive somebody opens by hand should not be 400 files with no type.
     */
    public static function assetPath(string $reference, string $name = ''): string
    {
        $extension = strtolower((string) pathinfo($name !== '' ? $name : $reference, PATHINFO_EXTENSION));
        $extension = preg_match('/^[a-z0-9]{1,8}$/', $extension) ? '.' . $extension : '';

        return self::ASSET_DIR . substr(hash('sha256', $reference), 0, 32) . $extension;
    }

    /** A space's archive file name — readable first, unique second. */
    public static function fileName(string $spaceTitle, string $spaceId): string
    {
        $slug = trim(preg_replace('/-+/', '-', preg_replace(
            '/[^A-Za-z0-9]+/', '-', self::asciiFold($spaceTitle)
        )) ?? '', '-');

        return ($slug !== '' ? $slug : 'space') . '--' . self::safe($spaceId) . '.zip';
    }

    /** Anything that has to become one path segment. */
    private static function safe(string $value): string
    {
        return preg_replace('/[^A-Za-z0-9_-]+/', '_', $value) ?? 'x';
    }

    private static function asciiFold(string $value): string
    {
        $folded = @iconv('UTF-8', 'ASCII//TRANSLIT', $value);

        // iconv is locale-dependent and can return false; the raw string still
        // makes a usable (if less pretty) name once the regex above runs.
        return $folded === false ? $value : $folded;
    }
}
