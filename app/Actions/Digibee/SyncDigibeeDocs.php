<?php

namespace App\Actions\Digibee;

use App\Support\Digibee\DigibeeDocPage;
use App\Support\Digibee\DigibeeDocsCorpus;
use App\Support\Digibee\DigibeeDocsIndex;
use App\Support\Digibee\DigibeeDocsSyncReport;
use Illuminate\Http\Client\Pool;
use Illuminate\Http\Client\Response;
use Illuminate\Support\Facades\Http;

/**
 * Mirrors Digibee's published documentation into the local corpus.
 *
 * The whole sync is public HTTP against `docs.digibee.com`: an index at
 * `/llms.txt` naming every page, and one Markdown URL per page. No credential
 * is involved, which is the reason this half of the knowledge base can be
 * scheduled inside the app while the pipeline half (PullDigibeePipelines)
 * cannot.
 *
 * **Re-runnable and non-destructive.** A page is written by path, so a re-sync
 * overwrites in place; a page Digibee has RETIRED is left on disk and simply
 * stops being listed in the manifest, which is what every reader iterates. That
 * is deliberate: deleting a file because one request failed is the failure mode
 * a mirror must not have, and an unlisted file costs a few KB.
 *
 * **A failed page does not end the sync.** The macro returns failed responses
 * instead of throwing (see AppServiceProvider), so one 404 in 581 pages is a
 * line in the report. The manifest is still written, over whatever DID arrive —
 * a corpus missing three pages is worth far more than no corpus at all.
 */
class SyncDigibeeDocs
{
    public function __construct(private readonly DigibeeDocsCorpus $corpus) {}

    /**
     * @param  string|null  $section  restrict to one `## ` heading of the index
     *                                ("Connectors & Triggers"), for a quick
     *                                partial re-sync
     */
    public function handle(bool $dryRun = false, ?string $section = null, ?int $limit = null): DigibeeDocsSyncReport
    {
        $indexUrl = (string) config('services.digibee.docs_index');
        $response = Http::digibeeDocs()->get($indexUrl);

        if ($response->failed()) {
            throw new \RuntimeException(
                "Digibee docs index unreachable ({$indexUrl}): HTTP {$response->status()}."
            );
        }

        $pages = DigibeeDocsIndex::parse($response->body());

        if ($section !== null) {
            $pages = array_values(array_filter(
                $pages,
                fn (DigibeeDocPage $page) => mb_strtolower($page->section) === mb_strtolower($section),
            ));
        }

        if ($limit !== null) {
            $pages = array_slice($pages, 0, $limit);
        }

        if ($dryRun) {
            return new DigibeeDocsSyncReport(pages: $pages, dryRun: true);
        }

        $fetched = 0;
        $changedKeys = [];
        $failures = [];

        foreach (array_chunk($pages, max(1, (int) config('services.digibee.docs_batch'))) as $batch) {
            foreach ($this->fetchBatch($batch) as $index => $body) {
                $page = $batch[$index];

                if ($body === null) {
                    $failures[] = "{$page->path}";

                    continue;
                }

                $fetched++;

                if ($this->corpus->put($page, $body)) {
                    $changedKeys[] = $page->key();
                }
            }
        }

        // Only the pages that actually arrived: a manifest listing a page with
        // no file behind it would make every reader guard for a null body.
        $written = array_values(array_filter(
            $pages,
            fn (DigibeeDocPage $page) => ! in_array($page->path, $failures, true),
        ));

        $this->corpus->writeManifest(
            $this->manifestPages($written, $section !== null || $limit !== null),
            rtrim((string) config('services.digibee.docs_url'), '/') . $indexUrl,
        );

        return new DigibeeDocsSyncReport(
            pages: $written,
            fetched: $fetched,
            changed: count($changedKeys),
            changedKeys: $changedKeys,
            failures: $failures,
        );
    }

    /**
     * What the manifest should list after this run.
     *
     * A FULL run replaces it: a page Digibee retired has to stop being listed,
     * which is the only way anything downstream learns it is gone.
     *
     * A PARTIAL run (`--section`, `--limit`) merges instead, and that is not a
     * nicety — writing only the subset is how `--limit=1` left a 581-page
     * corpus with a one-page manifest and every connector card reporting its
     * page as missing. The manifest is the index every reader iterates, so a
     * scoped re-sync of one section must leave the other six exactly as they
     * were.
     *
     * @param  list<DigibeeDocPage>  $written
     * @return list<DigibeeDocPage>
     */
    private function manifestPages(array $written, bool $partial): array
    {
        if (! $partial) {
            return $written;
        }

        $refreshed = array_column(
            array_map(fn (DigibeeDocPage $page) => [$page->path, $page], $written),
            1,
            0,
        );

        $merged = [];

        foreach ($this->corpus->pages() as $existing) {
            $merged[$existing->path] = $refreshed[$existing->path] ?? $existing;
            unset($refreshed[$existing->path]);
        }

        return array_values([...$merged, ...$refreshed]);
    }

    /**
     * One pool per batch, then ONE sequential retry for whatever failed.
     *
     * `Http::pool()` does not carry the macro's `retry()` — a pooled request is
     * built from the Pool, not from a configured PendingRequest — so the retry
     * that the rest of this app gets for free has to be spelled out here. Doing
     * it as a second pass rather than per request keeps a transient blip from
     * serializing the whole batch behind one slow page.
     *
     * @param  list<DigibeeDocPage>  $batch
     * @return array<int, string|null> body per index, null when it stayed behind
     */
    private function fetchBatch(array $batch): array
    {
        $timeout = (int) config('services.digibee.docs_timeout');

        $responses = Http::pool(fn (Pool $pool) => array_map(
            fn (DigibeeDocPage $page) => $pool->timeout($timeout)->connectTimeout(5)->get($page->url),
            $batch,
        ));

        $bodies = [];

        foreach ($batch as $index => $page) {
            $response = $responses[$index] ?? null;
            $ok = $response instanceof Response && $response->successful();

            if ($ok) {
                $bodies[$index] = $response->body();

                continue;
            }

            $retry = Http::digibeeDocs()->get($page->url);
            $bodies[$index] = $retry->successful() ? $retry->body() : null;
        }

        return $bodies;
    }
}
