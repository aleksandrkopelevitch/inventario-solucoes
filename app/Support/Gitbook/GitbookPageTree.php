<?php

namespace App\Support\Gitbook;

use App\Models\DocumentationPage;

/**
 * Resolves a GitBook space's page tree into the list of `DocumentationPage`s to
 * write — in reading order, each one already knowing its parent and its depth.
 *
 * GitBook nests to ANY depth (a `group` node holds pages; a `document` node can
 * itself hold child pages) while `documentation_pages` stops at
 * `DocumentationPage::MAX_DEPTH`, so the two shapes still don't match — but the
 * mismatch is now a CLAMP at the bottom instead of a flattening of everything:
 *
 * - Down to the cap, GitBook's nesting is reproduced as-is.
 * - Below it, a node hangs off the deepest ancestor still allowed to hold
 *   children, and the ancestry that could not be represented moves into its
 *   title ("Instalação › Requisitos"). Nothing is lost and reading order
 *   survives; what a reader loses is only the distinction between the levels
 *   that didn't fit. `collapsed()` counts them, so an import can say how much
 *   of a space was too deep to keep instead of implying it all came across.
 * - A `group` becomes a page of its own with no content — an empty heading, the
 *   way it reads in GitBook's own sidebar, and the only thing its children can
 *   hang from. `sections()` counts those separately so they don't quietly
 *   inflate the page count.
 *
 * With `$nest` false the old behaviour comes back whole: every page becomes a
 * root and carries its full ancestry in its title, and a `group` contributes
 * only that title. That is what `--flat` is for — a space worth keeping as one
 * flat list, or one so deep that nesting only moves the truncation around.
 *
 * Two node types are deliberately dropped, and both are counted so the import
 * can say so rather than losing them quietly:
 *
 * - `link`: an entry in the sidebar pointing somewhere else. No content.
 * - `computed`: a page GitBook generates live from an OpenAPI spec. Freezing
 *   today's render into a Markdown page would be a copy that silently rots;
 *   these belong back at their source.
 */
class GitbookPageTree
{
    /** @var array<int, GitbookPage> */
    private array $pages = [];

    /** @var array<string, int> */
    private array $skipped = ['link' => 0, 'computed' => 0];

    private int $sections = 0;

    private int $collapsed = 0;

    /**
     * @param  array<int, array<string, mixed>>  $tree  As returned by GitbookClient::pageTree()
     * @param  bool  $nest  Reproduce GitBook's nesting (default), or flatten it into the titles.
     */
    public function __construct(array $tree, private readonly bool $nest = true)
    {
        $this->walk($tree, []);
    }

    /** @return array<int, GitbookPage> */
    public function pages(): array
    {
        return $this->pages;
    }

    /** @return array<string, int> Counts per dropped node type, e.g. ['link' => 2, 'computed' => 0] */
    public function skipped(): array
    {
        return $this->skipped;
    }

    /** GitBook `group`s turned into empty section pages. */
    public function sections(): int
    {
        return $this->sections;
    }

    /** Pages that sat deeper than the cap and kept the collapsed ancestry in their title. */
    public function collapsed(): int
    {
        return $this->collapsed;
    }

    /**
     * @param  array<int, array<string, mixed>>  $nodes
     * @param  array<int, array{id: string, title: string, depth: int}>  $ancestors  The chain above this
     *                                                                               level, each carrying the depth it was ASSIGNED here — which is what
     *                                                                               the clamp reads, not GitBook's own nesting level.
     */
    private function walk(array $nodes, array $ancestors): void
    {
        foreach ($nodes as $node) {
            $type = $node['type'] ?? 'document';
            $title = trim((string) ($node['title'] ?? '')) ?: 'Sem título';

            if ($type === 'link' || $type === 'computed') {
                $this->skipped[$type]++;

                continue;
            }

            if (! $this->nest) {
                $this->flatten($node, $type, $title, $ancestors);

                continue;
            }

            $page = $this->place($node, $type, $title, $ancestors);
            $this->pages[] = $page;

            if ($page->isSection) {
                $this->sections++;
            }

            $this->walk($node['pages'] ?? [], [
                ...$ancestors,
                ['id' => $page->id, 'title' => $title, 'depth' => $page->depth],
            ]);
        }
    }

    /**
     * Where this node lands, given the chain above it.
     *
     * The parent is the deepest ancestor still allowed to hold children; when
     * that is not the immediate one, everything in between is ancestry this app
     * cannot represent, so it moves into the title rather than being dropped.
     *
     * @param  array<string, mixed>  $node
     * @param  array<int, array{id: string, title: string, depth: int}>  $ancestors
     */
    private function place(array $node, string $type, string $title, array $ancestors): GitbookPage
    {
        $parentIndex = null;

        foreach ($ancestors as $index => $ancestor) {
            if ($ancestor['depth'] < DocumentationPage::MAX_DEPTH - 1) {
                $parentIndex = $index;
            }
        }

        $parent = $parentIndex === null ? null : $ancestors[$parentIndex];
        // Everything between the chosen parent and this node — empty unless the
        // clamp skipped an ancestor, which is the only way a title gets a prefix.
        $collapsed = array_column(
            $parentIndex === null ? $ancestors : array_slice($ancestors, $parentIndex + 1),
            'title',
        );

        if ($collapsed !== []) {
            $this->collapsed++;
        }

        return new GitbookPage(
            id: (string) ($node['id'] ?? ''),
            title: $collapsed !== [] ? implode(' › ', [...$collapsed, $title]) : $title,
            path: (string) ($node['path'] ?? ''),
            trail: array_column($ancestors, 'title'),
            parentId: $parent['id'] ?? null,
            depth: $parent === null ? 0 : $parent['depth'] + 1,
            isSection: $type === 'group',
            originalTitle: $title,
        );
    }

    /**
     * `--flat`: one root page per document, ancestry in the title, groups
     * contributing nothing but theirs. Kept verbatim from before nesting
     * existed, so an operator can still get exactly the old shape.
     *
     * @param  array<string, mixed>  $node
     * @param  array<int, array{id: string, title: string, depth: int}>  $ancestors
     */
    private function flatten(array $node, string $type, string $title, array $ancestors): void
    {
        $trail = array_column($ancestors, 'title');

        if ($type !== 'group') {
            $this->pages[] = new GitbookPage(
                id: (string) ($node['id'] ?? ''),
                title: $trail !== [] ? implode(' › ', [...$trail, $title]) : $title,
                path: (string) ($node['path'] ?? ''),
                trail: $trail,
                originalTitle: $title,
            );
        }

        $this->walk($node['pages'] ?? [], [...$ancestors, ['id' => '', 'title' => $title, 'depth' => 0]]);
    }
}
