<?php

namespace App\Support\Gitbook;

/**
 * Flattens a GitBook space's page tree into a flat, `position`-ordered list of
 * top-level `documentation_pages` rows.
 *
 * This is the one real structural mismatch between the two apps: GitBook nests
 * to any depth (a `group` node holds pages; a `document` node can itself hold
 * child pages), while a DocumentationGroup here stops at
 * `DocumentationPage::MAX_DEPTH` levels. Depth-first
 * order is preserved, so the reading order survives the flattening even though
 * the nesting doesn't; with `$prefixTitles` (the default) a nested page also
 * carries its ancestry in its title — "Getting started › Instalação" — which
 * is what makes a flattened space still legible when you re-file its pages by
 * hand afterwards.
 *
 * The flattening stayed after the page tree gained levels of its own, and that
 * is a decision rather than an oversight: a capped depth still doesn't fit an
 * arbitrarily deep space, and re-importing with nesting would rewrite the
 * titles of every page already imported. Teaching this class to use the levels
 * that now exist is a migration of its own.
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

    /**
     * @param  array<int, array<string, mixed>>  $tree  As returned by GitbookClient::pageTree()
     */
    public function __construct(array $tree, private readonly bool $prefixTitles = true)
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

    /**
     * @param  array<int, array<string, mixed>>  $nodes
     * @param  array<int, string>  $trail
     */
    private function walk(array $nodes, array $trail): void
    {
        foreach ($nodes as $node) {
            $type = $node['type'] ?? 'document';
            $title = trim((string) ($node['title'] ?? '')) ?: 'Sem título';

            if ($type === 'link' || $type === 'computed') {
                $this->skipped[$type]++;

                continue;
            }

            // A `group` is pure structure — nothing to import, but its title is
            // the ancestry its children should carry.
            if ($type === 'group') {
                $this->walk($node['pages'] ?? [], [...$trail, $title]);

                continue;
            }

            $this->pages[] = new GitbookPage(
                id: (string) ($node['id'] ?? ''),
                title: $this->prefixTitles && $trail !== []
                    ? implode(' › ', [...$trail, $title])
                    : $title,
                path: (string) ($node['path'] ?? ''),
                trail: $trail,
            );

            // A document can hold children of its own; they inherit its title
            // as ancestry, exactly like a group's would.
            $this->walk($node['pages'] ?? [], [...$trail, $title]);
        }
    }
}
