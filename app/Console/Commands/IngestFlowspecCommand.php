<?php

namespace App\Console\Commands;

use App\Actions\Digibee\IngestFlowspec;
use App\Actions\Flowspec\SynthesizeTriggerSpec;
use App\Enums\DigibeeTriggerAuth;
use App\Enums\DigibeeTriggerKind;
use App\Support\Digibee\IngestionReport;
use App\Support\Digibee\TriggerSpec;
use Illuminate\Console\Command;
use JsonException;

/**
 * Writes a generated flowSpec into a real pipeline, by hand.
 *
 * A person runs this, the way a person ran the probe: it is how the ingestion
 * gets exercised before the self-correction loop (block F) drives it, and it
 * is the only place the write is deliberate rather than automatic. Like the
 * probe, it stays out of `routes/console.php` — there is nothing periodic
 * here.
 *
 * `--dry-run` resolves, validates and reports without writing, and it is the
 * right default habit: this writes to a realm running 201 live integrations,
 * and nothing in the platform deletes a pipeline it creates by mistake.
 */
class IngestFlowspecCommand extends Command
{
    protected $signature = 'digibee:flowspec:ingest
        {name : The pipeline name in the realm}
        {--file= : Path to a generated {meta, flowSpec} JSON document}
        {--create : Create the pipeline when the realm has no such name (permanent — nothing deletes a pipeline)}
        {--trigger= : Synthesize a triggerSpec: rest|http|http-file|scheduler|event}
        {--auth= : basic|key|jwt|none (default: what the tenant uses for that trigger kind)}
        {--methods= : Comma-separated HTTP methods (default POST)}
        {--uris= : Comma-separated REST uris (default: the platform path for the pipeline)}
        {--cron= : Cron expression, required by --trigger=scheduler}
        {--event= : Event name, required by --trigger=event}
        {--replace-trigger : Overwrite a triggerSpec the pipeline already has}
        {--force : Skip the confirmation — for a caller with no terminal to answer it}
        {--dry-run : Resolve, validate and report — write nothing}';

    protected $description = 'Write a generated flowSpec into a Digibee pipeline through the design API';

    public function handle(IngestFlowspec $ingest, SynthesizeTriggerSpec $triggers): int
    {
        $document = $this->document();

        if ($document === null) {
            return self::FAILURE;
        }

        $trigger = $this->trigger($triggers);

        if ($trigger === false) {
            return self::FAILURE;
        }

        $name = (string) $this->argument('name');
        $dryRun = (bool) $this->option('dry-run');

        // `--no-interaction` makes confirm() take its default, which is NO —
        // so a caller without a terminal writes nothing and reports success,
        // which is the worst of both. `--force` is the deliberate way past it,
        // and the correction loop (block F) will need exactly that.
        if (! $dryRun && ! $this->option('force')
            && ! $this->confirm("Escrever o flowSpec no pipeline \"{$name}\" do realm?", false)) {
            $this->components->warn('Nada foi escrito.');

            return self::SUCCESS;
        }

        $report = $ingest->handle(
            document: $document,
            pipelineName: $name,
            trigger: $trigger,
            create: (bool) $this->option('create'),
            replaceTrigger: (bool) $this->option('replace-trigger'),
            dryRun: $dryRun,
        );

        $this->report($report, $dryRun);

        return $report->ok() ? self::SUCCESS : self::FAILURE;
    }

    /** @return array<string, mixed>|null */
    private function document(): ?array
    {
        $path = (string) $this->option('file');

        if ($path === '' || ! is_file($path)) {
            $this->components->error('Passe --file com o caminho de um documento {meta, flowSpec}.');

            return null;
        }

        try {
            $decoded = json_decode((string) file_get_contents($path), true, 512, JSON_THROW_ON_ERROR);
        } catch (JsonException $e) {
            $this->components->error("O arquivo não é JSON válido: {$e->getMessage()}");

            return null;
        }

        return is_array($decoded) ? $decoded : null;
    }

    /** @return TriggerSpec|null|false false = the options are wrong, stop */
    private function trigger(SynthesizeTriggerSpec $triggers): TriggerSpec|null|false
    {
        $kind = (string) $this->option('trigger');

        if ($kind === '') {
            return null;
        }

        $resolved = DigibeeTriggerKind::tryFrom($kind);

        if ($resolved === null) {
            $this->components->error(
                "--trigger inválido: {$kind}. Válidos: "
                . implode(', ', array_column(DigibeeTriggerKind::cases(), 'value')) . '.'
            );

            return false;
        }

        $auth = (string) $this->option('auth');
        $resolvedAuth = $auth === '' ? null : DigibeeTriggerAuth::tryFrom($auth);

        if ($auth !== '' && $resolvedAuth === null) {
            $this->components->error(
                "--auth inválido: {$auth}. Válidos: "
                . implode(', ', array_column(DigibeeTriggerAuth::cases(), 'value')) . '.'
            );

            return false;
        }

        return $triggers->handle($resolved, array_filter([
            'auth'      => $resolvedAuth,
            'methods'   => $this->list('methods'),
            'uris'      => $this->list('uris'),
            'cron'      => (string) $this->option('cron') ?: null,
            'eventName' => (string) $this->option('event') ?: null,
        ]));
    }

    /** @return list<string>|null */
    private function list(string $option): ?array
    {
        $raw = (string) $this->option($option);

        if ($raw === '') {
            return null;
        }

        return array_values(array_filter(array_map('trim', explode(',', $raw))));
    }

    private function report(IngestionReport $report, bool $dryRun): void
    {
        $this->newLine();

        $this->table(['', ''], [
            ['pipeline', $report->pipelineName . ($report->pipelineId ? " ({$report->pipelineId})" : '')],
            ['versão', "v{$report->versionMajor}.{$report->versionMinor}"],
            ['flowSpec', "{$report->stepCount} step(s) em {$report->branchCount} branch(es)"],
            ['trigger', $report->trigger === null
                ? '—'
                : $report->trigger->kind->label() . ' / ' . $report->trigger->auth->label()
                    . ($report->triggerApplied ? ' (escrito)' : ' (não aplicado)')],
            ['escrito', $dryRun ? 'não (dry run)' : ($report->wrote ? 'sim' : 'não')],
            ['relido idêntico', $report->wrote ? ($report->verified ? 'sim' : 'NÃO') : '—'],
        ]);

        foreach ($report->changes as $change) {
            $this->line("  <fg=green>+</> {$change}");
        }

        foreach ($report->warnings as $warning) {
            $this->line("  <fg=yellow>!</> {$warning}");
        }

        foreach ($report->errors as $error) {
            $this->line("  <fg=red>x</> {$error}");
        }

        $this->newLine();

        if ($report->errors !== []) {
            $this->components->error('Nada foi escrito.');

            return;
        }

        if ($dryRun) {
            $this->components->info('Dry run: nada foi escrito.');

            return;
        }

        $report->confirmed()
            ? $this->components->info('flowSpec escrito e relido idêntico.')
            : $this->components->warn('Escrita feita, verificação não confirmada.');
    }
}
