<?php

namespace App\Console\Commands;

use App\Actions\Digibee\DeployPipeline;
use App\Support\Digibee\DeploymentReport;
use Illuminate\Console\Command;

/**
 * Deploys a pipeline and waits for the platform to settle.
 *
 * A person runs this, like the probe and the ingestion, and for the same
 * reason: it changes something on a realm that runs 201 live integrations.
 * Out of `routes/console.php` — nothing here is periodic.
 */
class DeployPipelineCommand extends Command
{
    protected $signature = 'digibee:pipeline:deploy
        {name : The pipeline name in the realm}
        {--environment=test : Which environment to deploy to (must be allowed in config)}
        {--size=SMALL : SMALL, MEDIUM or LARGE}
        {--no-redeploy : Refuse to replace an existing deployment}
        {--timeout=300 : Seconds to wait for the deployment to settle}
        {--force : Skip the confirmation}
        {--dry-run : Resolve and report — deploy nothing}';

    protected $description = 'Deploy a pipeline to an environment and wait for it to settle';

    public function handle(DeployPipeline $deploy): int
    {
        $name = (string) $this->argument('name');
        $environment = (string) $this->option('environment');
        $dryRun = (bool) $this->option('dry-run');

        if (! $dryRun && ! $this->option('force')
            && ! $this->confirm("Implantar \"{$name}\" em {$environment}?", false)) {
            $this->components->warn('Nada foi implantado.');

            return self::SUCCESS;
        }

        $report = $deploy->handle(
            pipelineName: $name,
            environment: $environment,
            size: (string) $this->option('size'),
            redeploy: ! $this->option('no-redeploy'),
            timeoutSeconds: (int) $this->option('timeout'),
            dryRun: $dryRun,
        );

        $this->report($report, $dryRun);

        return $report->ok() ? self::SUCCESS : self::FAILURE;
    }

    private function report(DeploymentReport $report, bool $dryRun): void
    {
        $this->newLine();

        $this->table(['', ''], [
            ['pipeline', $report->pipelineName . ($report->pipelineId ? " ({$report->pipelineId})" : '')],
            ['ambiente', $report->environment],
            ['deployment', $report->deploymentId ?? '—'],
            ['status', $report->status->label()],
            ['endpoint', $report->endpoint ?? '—'],
            ['réplicas', $report->engine['replicas'] ?? '—'],
            ['esperou', $report->waitedSeconds . 's'],
        ]);

        foreach ($report->warnings as $warning) {
            $this->line("  <fg=yellow>!</> {$warning}");
        }

        foreach ($report->errors as $error) {
            $this->line("  <fg=red>x</> {$error}");
        }

        $this->newLine();

        match (true) {
            $report->errors !== [] => $this->components->error('O deploy não ficou de pé.'),
            $dryRun                => $this->components->info('Dry run: nada foi implantado.'),
            $report->testable()    => $this->components->info('No ar, com URL — a bateria de testes já tem o que chamar.'),
            $report->live()        => $this->components->warn('No ar, mas sem URL: o pipeline não tem trigger web.'),
            default                => $this->components->warn('Implantado, ainda não estabilizado.'),
        };
    }
}
