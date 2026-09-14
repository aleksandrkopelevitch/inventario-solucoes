<?php

namespace App\Console\Commands;

use App\Actions\Flowspec\SynthesizeTriggerSpec;
use App\Enums\DigibeeTriggerAuth;
use App\Enums\DigibeeTriggerKind;
use App\Services\Digibee\PipelineHealingService;
use App\Support\Digibee\Healing\HealingReport;
use App\Support\Digibee\Testing\EndpointCredential;
use App\Support\Digibee\TriggerSpec;
use Illuminate\Console\Command;
use JsonException;

/**
 * Drives the healing loop by hand — write, deploy, test, correct, repeat.
 *
 * A person runs this, like the probe, the ingestion and the deploy, and for a
 * sharper reason than any of them: one invocation can write a pipeline and
 * deploy it several times over, in a realm that runs 201 live integrations and
 * that deletes nothing. It is out of `routes/console.php` and it always asks
 * before the first round unless `--force` is given.
 *
 * **Two different `--auth` options, deliberately spelled apart.** The trigger's
 * auth is what gets WRITTEN into the pipeline (how the endpoint will ask
 * callers to identify themselves); the endpoint's is what this command SENDS
 * when the battery calls it. They are unrelated credentials pointing in
 * opposite directions, and a single `--auth` reading as both is the kind of
 * collision that ends with a realm-wide token being posted at a runtime host.
 */
class HealPipelineCommand extends Command
{
    protected $signature = 'digibee:pipeline:heal
        {name : The pipeline name in the realm}
        {--file= : Path to a generated {meta, flowSpec} JSON document}
        {--environment=test : Which environment to deploy to and call (must be allowed in config)}
        {--rounds= : Write/deploy/test cycles allowed (default: config)}
        {--create : Create the pipeline when the realm has no such name (permanent — nothing deletes a pipeline)}
        {--trigger= : Synthesize a triggerSpec to write: rest|http|http-file|scheduler|event}
        {--trigger-auth= : basic|key|jwt|none — how the WRITTEN endpoint will authenticate its callers}
        {--endpoint-auth=none : basic|key|jwt|none — how THIS command authenticates when calling it}
        {--key-header=x-api-key : Header name for --endpoint-auth=key}
        {--force : Skip the confirmation}';

    protected $description = 'Write a flowSpec, deploy it, run the battery and correct it from what happened';

    public function handle(PipelineHealingService $healing, SynthesizeTriggerSpec $triggers): int
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
        $environment = (string) $this->option('environment');
        $rounds = (string) $this->option('rounds');

        if (! $this->option('force') && ! $this->confirm(
            "Escrever, implantar e testar \"{$name}\" em {$environment}, corrigindo até o limite de rodadas?",
            false,
        )) {
            $this->components->warn('Nada foi escrito.');

            return self::SUCCESS;
        }

        $report = $healing->heal(
            document: $document,
            pipelineName: $name,
            environment: $environment,
            trigger: $trigger,
            credential: $credential,
            create: (bool) $this->option('create'),
            maxRounds: $rounds === '' ? null : (int) $rounds,
        );

        $this->report($report);

        return $report->healed() ? self::SUCCESS : self::FAILURE;
    }

    private function report(HealingReport $report): void
    {
        $this->newLine();

        $this->table(['', ''], [
            ['pipeline', $report->pipelineName],
            ['ambiente', $report->environment],
            ['veredito', $report->verdict->label()],
            ['rodadas', (string) count($report->rounds)],
            ['implantações', (string) $report->deploys()],
            ['endpoint', $report->endpoint ?? '—'],
        ]);

        foreach ($report->rounds as $round) {
            $this->line(sprintf(
                '  <fg=gray>#%d</> chegou até <fg=cyan>%s</>: %s',
                $round->round,
                $round->reached(),
                $round->verdict->label(),
            ));

            foreach ($round->evidence as $line) {
                $this->line("      <fg=gray>-</> " . str_replace("\n", ' ', $line));
            }
        }

        $this->newLine();

        foreach ($report->warnings as $warning) {
            $this->line("  <fg=yellow>!</> {$warning}");
        }

        // The word, not the tally — the same distinction the battery itself
        // makes. A run that never judged the pipeline must not read as a
        // verdict about it.
        match (true) {
            $report->healed() => $this->components->info('Verde: a bateria rodou e o caminho feliz passou.'),
            $report->verdict->judgedThePipeline() => $this->components->warn($report->verdict->label() . '.'),
            default => $this->components->error($report->verdict->label() . '.'),
        };
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

        $auth = (string) $this->option('trigger-auth');
        $resolvedAuth = $auth === '' ? null : DigibeeTriggerAuth::tryFrom($auth);

        if ($auth !== '' && $resolvedAuth === null) {
            $this->components->error(
                "--trigger-auth inválido: {$auth}. Válidos: "
                . implode(', ', array_column(DigibeeTriggerAuth::cases(), 'value')) . '.'
            );

            return false;
        }

        return $triggers->handle($resolved, array_filter(['auth' => $resolvedAuth]));
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
