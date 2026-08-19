<?php

namespace App\Actions\Documentation;

use App\Contracts\Documentable;
use App\Models\DocumentationGroup;
use App\Services\DocumentationPageService;
use App\Support\Gitbook\GitbookAssetImporter;
use App\Support\Gitbook\GitbookClient;
use App\Support\Gitbook\GitbookImportReport;
use App\Support\Gitbook\GitbookMarkdownNormalizer;
use App\Support\Gitbook\GitbookPage;
use App\Support\Gitbook\GitbookPageTree;
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
 * **Re-runnable.** A page is matched inside the group by its title, so running
 * the import again updates the pages it already created instead of doubling
 * them, and re-orders them to match GitBook's current reading order. There is
 * deliberately no wrapping transaction: the work is dozens-to-hundreds of HTTP
 * requests, and a half-finished import that can simply be re-run is worth far
 * more than one that rolls back an hour of downloads because page 180 failed.
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
        bool $prefixTitles = true,
        bool $dryRun = false,
    ): GitbookImportReport {
        $space = $this->client->space($spaceId);
        $title = trim((string) ($space['title'] ?? '')) ?: 'GitBook ' . $spaceId;

        $tree = new GitbookPageTree($this->client->pageTree($spaceId), $prefixTitles);
        $found = $tree->pages();

        if ($dryRun) {
            return new GitbookImportReport(
                spaceId: $spaceId,
                spaceTitle: $title,
                planned: array_map(fn (GitbookPage $page) => $page->title, $found),
                skipped: $tree->skipped(),
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
        // A group can hold two pages with the same title (nothing stops it), so
        // claiming each row at most once is what keeps two identically-named
        // GitBook pages from collapsing into one.
        $claimed = [];

        foreach ($found as $position => $source) {
            $existing = $group->pages()
                ->where('title', $source->title)
                ->whereNotIn('id', $claimed)
                ->first();

            $page = $existing ?? $this->pages->create($group, $source->title);
            $existing ? $updated++ : $created++;
            $claimed[] = $page->id;

            // Needed by nothing here, but a page fetched fresh would lazy-load
            // its container the moment anything downstream touches it.
            $page->setRelation('container', $group);

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

            $page->update([
                'documentation' => $rehosted->markdown ?: null,
                // GitBook's reading order, preserved through the flattening.
                'position' => $position + 1,
            ]);
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
        );
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
