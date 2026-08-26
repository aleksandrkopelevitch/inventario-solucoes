<?php

namespace App\Support\Gitbook;

/**
 * One node of a GitBook space, resolved into everything the import needs to
 * create a `DocumentationPage`: its final title, where it hangs, and whether it
 * has content at all.
 *
 * `parentId` is another node's `id` (or null for a top-level page) — NOT a
 * `documentation_pages.id`: the import walks the list in reading order, so a
 * parent is always created before the children naming it, and the import keeps
 * its own id => model map to resolve them.
 *
 * Two titles, and the difference matters:
 *
 * - `title` is what the page gets HERE. Bare, normally — the nesting is what
 *   now carries the ancestry — except for a node too deep to nest (see
 *   `GitbookPageTree`), which keeps the collapsed part as a prefix.
 * - `originalTitle` is the node's own title in GitBook, which with `trail`
 *   reproduces `origin()`: the exact string the OLD flattening import wrote as
 *   a page title. That's what lets a re-run recognise the pages it created
 *   back then and re-shape them instead of duplicating them.
 */
class GitbookPage
{
    /**
     * @param  array<int, string>  $trail  Ancestor titles, outermost first.
     * @param  bool  $isSection  A GitBook `group`: pure structure, no content to fetch.
     */
    public function __construct(
        public readonly string $id,
        public readonly string $title,
        public readonly string $path,
        public readonly array $trail = [],
        public readonly ?string $parentId = null,
        public readonly int $depth = 0,
        public readonly bool $isSection = false,
        public readonly ?string $originalTitle = null,
    ) {}

    /**
     * "Getting started › Instalação" — the page's full ancestry, which is both
     * how it reads in GitBook and the title the flattening import used to give
     * it.
     */
    public function origin(): string
    {
        return implode(' › ', [...$this->trail, $this->originalTitle ?? $this->title]);
    }
}
