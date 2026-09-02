<?php

namespace App\Console\Commands;

use App\Actions\Digibee\IndexPipelineVocabulary;
use App\Actions\Digibee\PullDigibeePipelines;
use App\Support\Digibee\DigibeectlClient;
use Illuminate\Console\Command;

/**
 * Pulls every Digibee pipeline into the local export and rebuilds the tenant
 * vocabulary from it.
 *
 * **Run this on a developer/ops machine, never on the server.** `digibeectl`
 * authenticates with a credential that can create and delete deployments in
 * production, and Digibee publishes no read-only alternative (their API product
 * is beta and covers metrics only). So "periodic" here means a cron or Task
 * Scheduler entry on the machine that already holds that login, publishing the
 * derived `database/data/digibee_tenant_vocabulary.json` — the artifact is what
 * travels, not the credential.
 *
 * `--index-only` rebuilds the artifact from an export already on disk, which is
 * the common case while iterating on the redaction rules and the one path that
 * needs no credential at all.
 */
class PullDigibeePipelinesCommand extends Command
{
    protected $signature = 'digibee:pipelines:pull
        {--index-only : Skip digibeectl and rebuild the vocabulary from the export already on disk}
        {--no-index : Pull only, leaving database/data/digibee_tenant_vocabulary.json alone}';

    protected $description = 'Export Digibee pipelines via digibeectl and rebuild the tenant vocabulary';

    public function handle(DigibeectlClient $client, PullDigibeePipelines $pull, IndexPipelineVocabulary $index): int
    {
        if (! $this->option('index-only')) {
            if (! $client->available()) {
                $this->components->error("digibeectl not found at {$client->binary()}. Set DIGIBEECTL_BIN, or use --index-only.");

                return self::FAILURE;
            }

            $report = $pull->handle(fn (string $pipeline) => $this->line("  {$pipeline}"));

            $this->components->info("{$report['pipelines']} pipelines from {$report['projects']} projects.");

            foreach ($report['failures'] as $failure) {
                $this->components->warn($failure);
            }

            foreach ($report['pruned'] as $pruned) {
                $this->components->warn("removed, no longer in the tenant: {$pruned}");
            }

            if ($report['failures'] !== []) {
                $this->components->warn(
                    'nothing pruned: a pipeline this run could not reach is indistinguishable from one that was deleted.'
                );
            }
        }

        if ($this->option('no-index')) {
            return self::SUCCESS;
        }

        $built = $index->handle();

        $this->components->info(
            "{$built['pipelines']} pipelines indexed: {$built['connectors']} connectors, "
            . "{$built['globals']} global variables, {$built['accounts']} accounts ("
            . number_format($built['bytes'] / 1024, 1) . ' KB).'
        );

        foreach ($built['skipped'] as $skipped) {
            $this->components->warn("skipped (credencial literal): {$skipped}");
        }

        return self::SUCCESS;
    }
}
