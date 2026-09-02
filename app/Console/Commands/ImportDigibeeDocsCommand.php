<?php

namespace App\Console\Commands;

use App\Actions\Digibee\ImportDigibeeDocsNotebook;
use App\Support\Digibee\DigibeeDocsCorpus;
use Illuminate\Console\Command;

/**
 * Publishes the synced Digibee corpus as a caderno.
 *
 * Separate from `digibee:docs:sync` on purpose: the sync is HTTP and the import
 * is hundreds of database writes, they fail for unrelated reasons, and only one
 * of the two is worth running on a schedule. Same split, same reasoning, as
 * `gitbook:import` against the GitBook API.
 */
class ImportDigibeeDocsCommand extends Command
{
    protected $signature = 'digibee:docs:import
        {--dry-run : Report the tree that would be written, touching nothing}';

    protected $description = 'Import the synced Digibee documentation into its caderno';

    public function handle(DigibeeDocsCorpus $corpus, ImportDigibeeDocsNotebook $import): int
    {
        if (! $corpus->isSynced()) {
            $this->components->error('No corpus yet — run `php artisan digibee:docs:sync` first.');

            return self::FAILURE;
        }

        $report = $import->handle(dryRun: (bool) $this->option('dry-run'));

        $this->components->info(
            $this->option('dry-run')
                ? "{$report['planned']} pages would be written."
                : "{$report['created']} created, {$report['updated']} updated in \"{$report['notebook']->name}\"."
        );

        $this->line("  section pages (path levels Digibee publishes no page for): {$report['sections']}");
        $this->line("  collapsed past MAX_DEPTH, ancestry kept in the title: {$report['collapsed']}");

        if (! $this->option('dry-run')) {
            $this->line('  ' . route('notebooks.show', $report['notebook']));
        }

        return self::SUCCESS;
    }
}
