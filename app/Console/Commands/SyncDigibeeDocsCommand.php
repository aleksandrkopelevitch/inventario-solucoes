<?php

namespace App\Console\Commands;

use App\Actions\Digibee\BuildConnectorCards;
use App\Actions\Digibee\SyncDigibeeDocs;
use App\Support\Digibee\DigibeeDocPage;
use Illuminate\Console\Command;
use Illuminate\Http\Client\ConnectionException;

/**
 * Mirrors Digibee's published documentation locally and rebuilds the connector
 * reference cards from it.
 *
 * Public HTTP, no credential, read-only — which is what makes this the half of
 * the Digibee knowledge base that can be scheduled inside the app. The other
 * half (`digibee:pipelines:pull`) cannot, and says why.
 *
 * `--dry-run` fetches only the index and prints what a real run would pull, so
 * "did they publish a new section?" costs one request.
 */
class SyncDigibeeDocsCommand extends Command
{
    protected $signature = 'digibee:docs:sync
        {--section= : Only pull one section of the index ("Connectors & Triggers")}
        {--limit= : Stop after this many pages, for a quick check}
        {--no-cards : Skip rebuilding database/data/digibee_connector_cards.json}
        {--dry-run : Fetch the index and report what would be pulled, writing nothing}';

    protected $description = 'Sync the Digibee documentation corpus and rebuild the connector cards';

    public function handle(SyncDigibeeDocs $sync, BuildConnectorCards $cards): int
    {
        $limit = $this->option('limit');

        try {
            $report = $sync->handle(
                dryRun: (bool) $this->option('dry-run'),
                section: $this->option('section'),
                limit: $limit === null ? null : max(1, (int) $limit),
            );
        } catch (ConnectionException $e) {
            $this->components->error('docs.digibee.com unreachable: ' . $e->getMessage());

            return self::FAILURE;
        }

        if ($report->dryRun) {
            $this->components->info("{$report->total()} pages would be pulled:");

            foreach ($this->sections($report->pages) as $section => $count) {
                $this->line("  {$section}: {$count}");
            }

            return self::SUCCESS;
        }

        $this->components->info("{$report->fetched} pages fetched, {$report->changed} changed.");

        foreach (array_slice($report->changedKeys, 0, 20) as $key) {
            $this->line("  ~ {$key}");
        }

        if (count($report->changedKeys) > 20) {
            $this->line('  … ' . (count($report->changedKeys) - 20) . ' more');
        }

        foreach ($report->failures as $failure) {
            $this->components->warn("stayed behind: {$failure}");
        }

        if (! $this->option('no-cards')) {
            $built = $cards->handle();
            $this->components->info(
                "{$built['built']} connector cards rebuilt (" . number_format($built['bytes'] / 1024, 1) . ' KB).'
            );

            foreach ($built['missing'] as $missing) {
                $this->components->warn("no card: {$missing}");
            }

            if ($built['noParameters'] !== []) {
                $this->components->warn(
                    'card without parameters (check the page, or the connector really takes none): '
                    . implode(', ', $built['noParameters'])
                );
            }
        }

        return $report->failures === [] ? self::SUCCESS : self::FAILURE;
    }

    /**
     * @param  list<DigibeeDocPage>  $pages
     * @return array<string, int>
     */
    private function sections(array $pages): array
    {
        $counts = [];

        foreach ($pages as $page) {
            $counts[$page->section] = ($counts[$page->section] ?? 0) + 1;
        }

        return $counts;
    }
}
