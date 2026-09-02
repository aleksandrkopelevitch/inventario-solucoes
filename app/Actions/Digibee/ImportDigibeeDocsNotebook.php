<?php

namespace App\Actions\Digibee;

use App\Models\DocumentationPage;
use App\Models\Notebook;
use App\Models\Solution;
use App\Services\DocumentationPageService;
use App\Support\Digibee\DigibeeDocPage;
use App\Support\Digibee\DigibeeDocsCorpus;
use App\Support\Digibee\DigibeeMarkdownNormalizer;
use Illuminate\Support\Str;

/**
 * Publishes the synced corpus as a caderno, so Digibee's manual is searchable
 * and attachable exactly like every other body of documentation in here.
 *
 * **The corpus is the storage; this is a second consumer of it.** The connector
 * cards read the same files, and neither reads the other — so a broken import
 * cannot take the flowSpec reference down with it, and the caderno can be
 * deleted and rebuilt without re-fetching anything.
 *
 * The caderno is linked to the "Digibee (iPaaS)" Solution through the ordinary
 * `notebook_solution` pivot: a caderno describing 0..N solutions is exactly
 * what this is, and it makes the manual reachable from the same place as
 * everything else written about that system.
 *
 * Three things the shape of this corpus forces:
 *
 * - **The tree comes from the URL path**, since that is the only hierarchy
 *   Digibee publishes. 49 of those path segments have no page of their own
 *   ("resources/best-practices" has 15 children and no body), so they become
 *   empty section pages — the same thing ImportGitbookSpace does for a GitBook
 *   `group`.
 * - **33 pages sit deeper than `DocumentationPage::MAX_DEPTH`** and are
 *   collapsed onto the deepest ancestor that fits, carrying the skipped
 *   ancestry in their title. Same rule, same reason, as the GitBook import.
 * - **A page is matched by title WITHIN ITS PARENT.** Titles repeat across the
 *   corpus (every Release Notes month is called "August") — 17 of them — but
 *   never under the same parent, so the pair is unique and a re-import updates
 *   in place instead of duplicating 581 pages.
 */
class ImportDigibeeDocsNotebook
{
    public function __construct(
        private readonly DigibeeDocsCorpus $corpus,
        private readonly DigibeeMarkdownNormalizer $normalizer,
        private readonly DocumentationPageService $pages,
    ) {}

    /**
     * @return array{notebook: Notebook|null, created: int, updated: int, sections: int, collapsed: int, planned: int}
     */
    public function handle(bool $dryRun = false): array
    {
        $nodes = $this->nodes();

        if ($dryRun) {
            return [
                'notebook'  => null,
                'created'   => 0,
                'updated'   => 0,
                'sections'  => count(array_filter($nodes, fn (array $node) => $node['page'] === null)),
                'collapsed' => count(array_filter($nodes, fn (array $node) => $node['collapsed'])),
                'planned'   => count($nodes),
            ];
        }

        $notebook = $this->notebook();
        $unclaimed = $notebook->pages()->get();
        $models = [];
        $positions = [];
        $created = 0;
        $updated = 0;

        foreach ($nodes as $key => $node) {
            $parent = $node['parent'] === null ? null : ($models[$node['parent']] ?? null);
            $existing = $unclaimed->first(
                fn (DocumentationPage $page) => $page->title === $node['title'] && $page->parent_id === $parent?->id
            );

            $page = $existing ?? $this->pages->create($notebook, $node['title'], $parent);
            $existing ? $updated++ : $created++;
            $unclaimed = $unclaimed->reject(fn (DocumentationPage $candidate) => $candidate->is($page))->values();
            $models[$key] = $page;

            $slot = $parent?->id ?? 'root';
            $positions[$slot] = ($positions[$slot] ?? 0) + 1;

            // `parent_id` is not fillable — the tree is written through the
            // relation, like everywhere else in this module.
            $page->parent()->associate($parent);
            $page->title = $node['title'];
            $page->position = $positions[$slot];
            $page->documentation = $node['page'] === null
                ? null
                : $this->normalizer->normalize((string) $this->corpus->markdown($key), $node['page']);
            $page->save();
        }

        return [
            'notebook'  => $notebook,
            'created'   => $created,
            'updated'   => $updated,
            'sections'  => count(array_filter($nodes, fn (array $node) => $node['page'] === null)),
            'collapsed' => count(array_filter($nodes, fn (array $node) => $node['collapsed'])),
            'planned'   => count($nodes),
        ];
    }

    /**
     * Every page to write, keyed by doc key, PARENTS FIRST.
     *
     * Ordering by path depth is what lets the loop above resolve a parent from
     * `$models` without a second pass: a node's parent is always shallower than
     * it, so it has already been handled.
     *
     * @return array<string, array{page: DigibeeDocPage|null, parent: string|null, title: string, collapsed: bool}>
     */
    private function nodes(): array
    {
        $pages = [];

        foreach ($this->corpus->pages() as $page) {
            $pages[$page->key()] = $page;
        }

        // Path segments that hold children but publish no page of their own.
        $keys = array_keys($pages);

        foreach ($keys as $key) {
            $parent = $pages[$key]->parentKey();

            while ($parent !== null && ! isset($pages[$parent])) {
                $pages[$parent] = null;
                $parent = $this->parentOf($parent);
            }
        }

        uksort($pages, fn (string $a, string $b) => substr_count($a, '/') <=> substr_count($b, '/') ?: strcmp($a, $b));

        $nodes = [];

        foreach ($pages as $key => $page) {
            [$parent, $prefix] = $this->placement($key, $nodes);

            $title = $page?->title ?: $this->titleFrom($key);

            $nodes[$key] = [
                'page'      => $page,
                'parent'    => $parent,
                'title'     => $prefix === '' ? $title : "{$prefix} › {$title}",
                'collapsed' => $prefix !== '',
            ];
        }

        return $nodes;
    }

    /**
     * Where a key hangs, and what its title has to carry because of it.
     *
     * A page deeper than the cap is attached to the deepest ancestor that fits
     * and takes the skipped segments into its title — nothing is dropped, it
     * just stops being a level.
     *
     * @param  array<string, array{parent: string|null, title: string}>  $placed
     * @return array{0: string|null, 1: string}
     */
    private function placement(string $key, array $placed): array
    {
        $parent = $this->parentOf($key);
        $skipped = [];

        while ($parent !== null && ($this->depthOf($parent, $placed) >= DocumentationPage::MAX_DEPTH - 1)) {
            array_unshift($skipped, $this->titleFrom($parent));
            $parent = $this->parentOf($parent);
        }

        return [$parent, implode(' › ', $skipped)];
    }

    /**
     * How deep a already-placed key sits in the TREE — which is not its path
     * depth once something above it has been collapsed.
     *
     * @param  array<string, array{parent: string|null, title: string}>  $placed
     */
    private function depthOf(string $key, array $placed): int
    {
        $depth = 0;
        $cursor = $placed[$key]['parent'] ?? null;

        while ($cursor !== null) {
            $depth++;
            $cursor = $placed[$cursor]['parent'] ?? null;
        }

        return $depth;
    }

    private function parentOf(string $key): ?string
    {
        $segments = explode('/', $key);
        array_pop($segments);

        // `documentation` alone is the corpus root, not a page.
        return count($segments) <= 1 ? null : implode('/', $segments);
    }

    /** A readable title for a path segment that has no page of its own. */
    private function titleFrom(string $key): string
    {
        $segments = explode('/', $key);

        return Str::of((string) end($segments))->replace('-', ' ')->title()->value();
    }

    /**
     * The caderno, reused by name so a re-import updates instead of duplicating,
     * and linked to the Digibee solution when the catalog has one.
     */
    private function notebook(): Notebook
    {
        $name = (string) config('services.digibee.docs_notebook');
        $notebook = Notebook::where('name', $name)->first();

        if ($notebook === null) {
            $base = Str::slug($name) ?: 'digibee';
            $slug = $base;
            $suffix = 1;

            while (Notebook::where('slug', $slug)->exists()) {
                $slug = $base . '-' . (++$suffix);
            }

            $notebook = Notebook::create(['name' => $name, 'slug' => $slug]);
        }

        $solution = Solution::query()->whereFolded('name', 'digibee')->first();

        if ($solution !== null) {
            // syncWithoutDetaching: the link is ours to add, and somebody may
            // have linked this caderno to something else on purpose.
            $notebook->solutions()->syncWithoutDetaching([$solution->id]);
        }

        return $notebook;
    }
}
