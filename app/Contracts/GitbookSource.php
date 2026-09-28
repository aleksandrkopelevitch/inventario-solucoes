<?php

namespace App\Contracts;

/**
 * Where a GitBook import READS from.
 *
 * `ImportGitbookSpace` asks exactly four questions — what is this space called,
 * what is its page tree, what is one page's Markdown, and where do its files
 * live — and this is that list. Everything else the import does (the tree
 * clamp, title matching, re-shaping a flat import, `--dated`'s deletions, the
 * asset rewrite) is decided from the answers and does not care who gave them.
 *
 * Two implementations, and the difference between them is the whole reason
 * this exists:
 *
 * - `App\Support\Gitbook\GitbookClient` — the live API. It already had these
 *   four methods with these four signatures; the interface was extracted from
 *   it rather than designed against it.
 * - `App\Support\Gitbook\GitbookArchive` — a `.zip` written by
 *   `gitbook:archive`, read with no network at all.
 *
 * So `gitbook:restore` is not a second import: it is the same action, handed a
 * different source. A behaviour that exists in one and not the other would be a
 * bug in both, and the archive is worth having precisely because the day it
 * matters is the day the live source is gone.
 */
interface GitbookSource
{
    /** Space metadata; only `title` is read. */
    public function space(string $spaceId): array;

    /**
     * The published revision's page tree, as GitBook's own nested nodes
     * (`document` / `group` / `link` / `computed`) — `GitbookPageTree` is what
     * makes sense of them.
     *
     * @return array<int, array<string, mixed>>
     */
    public function pageTree(string $spaceId): array;

    /** One page's body as GitBook-flavoured Markdown ('' for a page with none). */
    public function pageMarkdown(string $spaceId, string $pageId): string;

    /**
     * Every asset the space's Markdown can refer to, keyed by the id that
     * appears in the reference (`/files/{id}` → `{id}`).
     *
     * An ARCHIVE also keys absolute URLs by the URL itself, which is what lets
     * a restore resolve a reference that was never a GitBook file id without
     * reaching the network — see `GitbookAssetImporter::source()`.
     *
     * @return array<string, array{url: string, name: string}>
     */
    public function files(string $spaceId): array;
}
