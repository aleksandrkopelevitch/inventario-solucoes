<?php

namespace App\Actions\Documentation;

use App\Contracts\Documentable;
use App\Models\DocumentationGroup;
use App\Models\DocumentationPage;
use App\Services\DocumentationPageService;
use App\Support\Gitbook\GitbookAssetImporter;
use App\Support\Gitbook\GitbookClient;
use App\Support\Gitbook\GitbookImportReport;
use App\Support\Gitbook\GitbookMarkdownNormalizer;
use App\Support\Gitbook\GitbookPage;
use App\Support\Gitbook\GitbookPageTree;
use Illuminate\Support\Collection;
use Illuminate\Support\Str;

/**
 * Pulls one GitBook space into a standalone DocumentationGroup — the landing
 * zone the content is then re-filed from by hand, into the Solution (or group)
 * it really belongs to.
 *
 * A group rather than a Solution on purpose: a space rarely maps 1:1 onto one
 * solution in this inventory, and guessing wrong scatters pages across records
 * that then have to be un-scattered. Importing into a group named after the
 * space keeps the whole thing in one legible place, editable and copyable,
 * until someone decides where each page goes.
 *
 * **The space's SHAPE comes across too** (`GitbookPageTree` resolves it): a
 * page keeps its parent, a `group` becomes an empty section page above its
 * children, and only what sits deeper than `DocumentationPage::MAX_DEPTH` is
 * collapsed into a title prefix. `--flat` brings back the old behaviour where
 * every page was a root carrying its full ancestry in its title.
 *
 * **Re-runnable, and a re-run MIGRATES.** A page is matched inside the group by
 * title, and the legacy flattened title (`GitbookPage::origin()` — exactly what
 * the old import wrote) is one of the things matched: so re-importing a space
 * that came in flat renames its pages down to their bare titles and hangs them
 * off each other, in place, rather than duplicating the corpus next to itself.
 * Slugs deliberately do NOT follow the rename — a page's URL stays stable, the
 * same rule the rest of the module keeps.
 *
 * There is deliberately no wrapping transaction: the work is dozens-to-hundreds
 * of HTTP requests, and a half-finished import that can simply be re-run is
 * worth far more than one that rolls back an hour of downloads because page 180
 * failed.
 */
class ImportGitbookSpace
{
    public function __construct(
        private readonly GitbookClient $client,
        private readonly GitbookMarkdownNormalizer $normalizer,
        private readonly GitbookAssetImporter $assets,
        private readonly DocumentationPageService $pages,
    ) {}

    public function handle(
        string $spaceId,
        ?string $groupName = null,
        bool $nest = true,
        bool $dryRun = false,
    ): GitbookImportReport {
        $space = $this->client->space($spaceId);
        $title = trim((string) ($space['title'] ?? '')) ?: 'GitBook ' . $spaceId;

        $tree = new GitbookPageTree($this->client->pageTree($spaceId), $nest);
        $found = $tree->pages();

        if ($dryRun) {
            return new GitbookImportReport(
                spaceId: $spaceId,
                spaceTitle: $title,
                // Indented, so a dry run shows the SHAPE it would write and not
                // just a list of names — the shape is the point now.
                planned: array_map(
                    fn (GitbookPage $page) => str_repeat('  ', $page->depth) . $page->title . ($page->isSection ? ' (seção)' : ''),
                    $found,
                ),
                skipped: $tree->skipped(),
                sections: $tree->sections(),
                collapsed: $tree->collapsed(),
            );
        }

        $group = $this->group($groupName ?: $title);

        // Fetched once for the whole space: it is the only place an embedded
        // asset's real download URL exists (its Markdown reference is a GitBook
        // file id, not a URL — see GitbookAssetImporter).
        $spaceFiles = $this->client->files($spaceId);

        $created = 0;
        $updated = 0;
        $assets = 0;
        $failures = [];
        // The group's pages, held in memory for the whole run: matching walks
        // this set instead of querying per page (the biggest imported space has
        // 133 of them), and a matched row leaves it — which is what keeps two
        // identically-named GitBook pages from collapsing into one.
        $unclaimed = $group->pages()->get();
        // GitBook node id => the page it became, so a child can find its parent.
        // Reading order guarantees the parent was handled first.
        $models = [];
        // Next `position` per sibling list: `position` orders a page among its
        // siblings, so one counter per parent, not one for the whole space.
        $positions = [];

        foreach ($found as $source) {
            $parent = $source->parentId === null ? null : ($models[$source->parentId] ?? null);
            $existing = $this->match($unclaimed, $source, $parent);

            $page = $existing ?? $this->pages->create($group, $source->title, $parent);
            $existing ? $updated++ : $created++;
            $unclaimed = $unclaimed->reject(fn (DocumentationPage $candidate) => $candidate->is($page))->values();
            $models[$source->id] = $page;

            // Needed by nothing here, but a page fetched fresh would lazy-load
            // its container the moment anything downstream touches it.
            $page->setRelation('container', $group);

            $key = $parent?->id ?? 'root';
            $positions[$key] = ($positions[$key] ?? 0) + 1;

            // `parent_id` is not fillable — the tree is written through the
            // relation. Set on every pass, not only on create: this is what
            // re-shapes a space that was imported flat.
            $page->parent()->associate($parent);
            $page->title = $source->title;
            $page->position = $positions[$key];
            $page->save();

            // A GitBook `group` has no content to fetch, and nothing here
            // should erase what a person may have written INTO the section page
            // afterwards — for a page with no source of truth in GitBook, no
            // source of truth here either.
            if ($source->isSection) {
                continue;
            }

            $markdown = $this->normalizer->normalize(
                $this->client->pageMarkdown($spaceId, $source->id)
            );

            // Re-importing replaces the page's content wholesale, so its old
            // embedded media would otherwise accumulate as orphans.
            if ($existing) {
                $page->clearMediaCollection(Documentable::DOCS_COLLECTION);
            }

            $rehosted = $this->assets->rehost($page, $markdown, $spaceFiles);
            $assets += $rehosted->imported;
            $failures = [...$failures, ...array_map(
                fn (string $failure) => $source->title . ': ' . $failure,
                $rehosted->failed,
            )];

            $page->update(['documentation' => $rehosted->markdown ?: null]);
        }

        return new GitbookImportReport(
            spaceId: $spaceId,
            spaceTitle: $title,
            group: $group,
            created: $created,
            updated: $updated,
            assets: $assets,
            skipped: $tree->skipped(),
            failures: $failures,
            sections: $tree->sections(),
            collapsed: $tree->collapsed(),
        );
    }

    /**
     * The row this GitBook node already has in the group, if any — tried three
     * ways, most specific first:
     *
     * 1. Same title AND already under the right parent: a plain re-import.
     * 2. The LEGACY flattened title ("Começando › Instalação"), which is what
     *    the old import wrote. Matching it is what makes a re-run re-shape the
     *    space in place instead of importing a second copy beside it.
     * 3. Same title anywhere in the group — a page whose parent moved in
     *    GitBook, or one imported at a different depth before.
     *
     * @param  Collection<int, DocumentationPage>  $unclaimed
     */
    private function match(Collection $unclaimed, GitbookPage $source, ?DocumentationPage $parent): ?DocumentationPage
    {
        return $unclaimed->first(fn (DocumentationPage $page) => $page->title === $source->title && $page->parent_id === $parent?->id)
            ?? $unclaimed->first(fn (DocumentationPage $page) => $page->title === $source->origin())
            ?? $unclaimed->first(fn (DocumentationPage $page) => $page->title === $source->title);
    }

    /**
     * The group for this space — reused when it's already there, which is what
     * makes a second run an update rather than a duplicate.
     */
    private function group(string $name): DocumentationGroup
    {
        $existing = DocumentationGroup::where('name', $name)->first();

        if ($existing) {
            return $existing;
        }

        $base = Str::slug($name) ?: 'gitbook';
        $slug = $base;
        $suffix = 1;

        while (DocumentationGroup::where('slug', $slug)->exists()) {
            $slug = $base . '-' . (++$suffix);
        }

        return DocumentationGroup::create(['name' => $name, 'slug' => $slug]);
    }
}
