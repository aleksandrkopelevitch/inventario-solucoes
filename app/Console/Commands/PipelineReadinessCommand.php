<?php

namespace App\Console\Commands;

use App\Actions\Digibee\AssessPromotion;
use App\Actions\Flowspec\SynthesizeTriggerSpec;
use App\Enums\DigibeeTriggerAuth;
use App\Enums\DigibeeTriggerKind;
use App\Services\Digibee\PipelineHealingService;
use App\Support\Digibee\PromotionReadiness;
use App\Support\Digibee\Testing\EndpointCredential;
use App\Support\Digibee\TriggerSpec;
use Illuminate\Console\Command;
use JsonException;

/**
 * Runs the lifecycle in `test` and answers whether a person should promote.
 *
 * It never promotes. The agent's reach stops at `test` by decision — promotion
 * is a human clicking in the Digibee panel — so what this command produces is a
 * verdict to act on, plus the VERSION to look for in that panel.
 *
 * **The evidence is always produced by this same invocation**, and that is the
 * design rather than a convenience: a verdict resting on last week's green run
 * says nothing about the pipeline as it stands, and the drift check exists
 * precisely because the canvas can rewrite it in between. Making the run fresh
 * removes the whole class of stale evidence instead of trying to date it.
 *
 * Out of `routes/console.php`, like every verb here, and it confirms first: one
 * invocation can deploy several times in `test`, and this platform deletes
 * nothing.
 */
class PipelineReadinessCommand extends Command
{
    protected $signature = 'digibee:pipeline:readiness
        {name : The pipeline name in the realm}
        {--file= : Path to a generated {meta, flowSpec} JSON document}
        {--environment=test : Which environment to produce the evidence in}
        {--rounds= : Healing cycles allowed}
        {--trigger= : Synthesize a triggerSpec to write: rest|http|http-file|scheduler|event}
        {--endpoint-auth=none : basic|key|jwt|none — how the battery authenticates when calling}
        {--key-header=x-api-key : Header name for --endpoint-auth=key}
        {--force : Skip the confirmation}';

    protected $description = 'Heal a pipeline in test and say whether it is fit to promote by hand';

    public function handle(PipelineHealingService $healing, AssessPromotion $assess, SynthesizeTriggerSpec $triggers): int
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
            "Curar \"{$name}\" em {$environment} e avaliar se está pronto para promoção?",
            false,
        )) {
            $this->components->warn('Nada foi feito.');

            return self::SUCCESS;
        }

        $evidence = $healing->heal(
            document: $document,
            pipelineName: $name,
            environment: $environment,
            trigger: $trigger,
            credential: $credential,
            maxRounds: $rounds === '' ? null : (int) $rounds,
        );

        $this->line('  <fg=gray>evidência:</> ' . $evidence->verdict->label());

        $this->report($assess->handle($evidence));

        return self::SUCCESS;
    }

    private function report(PromotionReadiness $readiness): void
    {
        $this->newLine();

        $this->table(['', ''], [
            ['pipeline', $readiness->pipelineName],
            ['testado em', $readiness->testedIn],
            ['versão armazenada', $readiness->version ?? '—'],
            ['endpoint', $readiness->endpoint ?? '—'],
            ['pronto para promover', $readiness->ready ? 'sim' : 'não'],
        ]);

        // Every blocker, never just the first: somebody deciding whether to
        // promote deserves the whole list rather than a queue of discoveries.
        foreach ($readiness->blockers as $blocker) {
            $this->line("  <fg=red>x</> {$blocker}");
        }

        foreach ($readiness->notes as $note) {
            $this->line("  <fg=gray>-</> {$note}");
        }

        $this->newLine();

        $readiness->ready
            ? $this->components->info('Pronto: promova ' . ($readiness->version ?? 'a versão armazenada') . ' pelo painel da Digibee.')
            : $this->components->warn('Não promova ainda.');
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
