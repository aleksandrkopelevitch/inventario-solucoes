<?php

namespace App\Console\Commands;

use App\Actions\Digibee\PromotePipeline;
use App\Actions\Flowspec\SynthesizeTriggerSpec;
use App\Enums\DigibeeTriggerAuth;
use App\Enums\DigibeeTriggerKind;
use App\Services\Digibee\PipelineHealingService;
use App\Support\Digibee\PromotionReport;
use App\Support\Digibee\Testing\EndpointCredential;
use App\Support\Digibee\TriggerSpec;
use Illuminate\Console\Command;
use JsonException;

/**
 * Runs the lifecycle end to end: heal in `test`, then ask the gate about
 * production.
 *
 * **The evidence is always produced by this same invocation**, and that is the
 * design rather than a convenience. A promotion authorised by a green run from
 * last week says nothing about the pipeline as it stands now — and the gate's
 * drift check exists precisely because the canvas can rewrite a pipeline
 * between the two. Making the run fresh removes the whole class of stale
 * evidence instead of trying to date it.
 *
 * Out of `routes/console.php`, like every other verb here, and it confirms
 * before it starts: one invocation can deploy several times in `test` and once
 * in production.
 */
class PromotePipelineCommand extends Command
{
    protected $signature = 'digibee:pipeline:promote
        {name : The pipeline name in the realm}
        {--file= : Path to a generated {meta, flowSpec} JSON document}
        {--from=test : Which environment the evidence is produced in}
        {--to=prod : Which environment to promote to (must be allowed in config)}
        {--size=SMALL : Runtime configuration size to promote with}
        {--rounds= : Healing cycles allowed in the evidence environment}
        {--trigger= : Synthesize a triggerSpec to write: rest|http|http-file|scheduler|event}
        {--endpoint-auth=none : basic|key|jwt|none — how the battery authenticates when calling}
        {--key-header=x-api-key : Header name for --endpoint-auth=key}
        {--dry-run : Judge and report — promote nothing}
        {--force : Skip the confirmation}';

    protected $description = 'Heal a pipeline in test and, only if it comes out green, promote it';

    public function handle(PipelineHealingService $healing, PromotePipeline $promote, SynthesizeTriggerSpec $triggers): int
    {
        $document = $this->document();

        if ($document === null) {
            return self::FAILURE;
        }

        $trigger = $this->trigger($triggers);

        if ($trigger === false) {
            return self::FAILURE;
        }

        $credential = $this->credential();

        if ($credential === false) {
            return self::FAILURE;
        }

        $name = (string) $this->argument('name');
        $from = (string) $this->option('from');
        $to = (string) $this->option('to');
        $dryRun = (bool) $this->option('dry-run');
        $rounds = (string) $this->option('rounds');

        if (! $dryRun && ! $this->option('force') && ! $this->confirm(
            "Curar \"{$name}\" em {$from} e, se ficar verde, promover para {$to}?",
            false,
        )) {
            $this->components->warn('Nada foi feito.');

            return self::SUCCESS;
        }

        $this->components->info("Produzindo evidência em {$from}…");

        $evidence = $healing->heal(
            document: $document,
            pipelineName: $name,
            environment: $from,
            trigger: $trigger,
            credential: $credential,
            maxRounds: $rounds === '' ? null : (int) $rounds,
        );

        $this->line('  <fg=gray>evidência:</> ' . $evidence->verdict->label());

        $report = $promote->handle(
            evidence: $evidence,
            toEnvironment: $to,
            size: (string) $this->option('size'),
            dryRun: $dryRun,
        );

        $this->report($report);

        return $report->promoted || $dryRun && ! $report->refused() ? self::SUCCESS : self::FAILURE;
    }

    private function report(PromotionReport $report): void
    {
        $this->newLine();

        $this->table(['', ''], [
            ['pipeline', $report->pipelineName],
            ['evidência de', $report->fromEnvironment],
            ['destino', $report->toEnvironment],
            ['promovido', $report->promoted ? 'sim' : 'não'],
            ['endpoint', $report->deployment?->endpoint ?? '—'],
        ]);

        // Every refusal, never just the first: somebody asking why this is not
        // in production deserves the whole list rather than a queue of
        // one-at-a-time discoveries.
        foreach ($report->refusals as $refusal) {
            $this->line("  <fg=red>x</> {$refusal}");
        }

        foreach ($report->warnings as $warning) {
            $this->line("  <fg=yellow>!</> {$warning}");
        }

        $this->newLine();

        if ($report->promoted) {
            $this->components->info('Promovido.');

            return;
        }

        $this->components->warn($report->refused() ? 'O portão recusou.' : 'Nada foi promovido.');
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

        return $triggers->handle($resolved, []);
    }

    /** @return EndpointCredential|null|false false = the options are wrong, stop */
    private function credential(): EndpointCredential|null|false
    {
        $auth = DigibeeTriggerAuth::tryFrom((string) $this->option('endpoint-auth'));

        if ($auth === null) {
            $this->components->error(
                '--endpoint-auth inválido. Válidos: '
                . implode(', ', array_column(DigibeeTriggerAuth::cases(), 'value')) . '.'
            );

            return false;
        }

        return match ($auth) {
            DigibeeTriggerAuth::None      => null,
            DigibeeTriggerAuth::BasicAuth => EndpointCredential::basic(
                (string) $this->ask('Usuário do endpoint'),
                (string) $this->secret('Senha do endpoint'),
            ),
            DigibeeTriggerAuth::KeyAuth => EndpointCredential::apiKey(
                (string) $this->secret('API key do endpoint'),
                (string) $this->option('key-header'),
            ),
            DigibeeTriggerAuth::Jwt => EndpointCredential::jwt((string) $this->secret('JWT do endpoint')),
        };
    }
}
