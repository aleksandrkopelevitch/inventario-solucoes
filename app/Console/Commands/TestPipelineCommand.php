<?php

namespace App\Console\Commands;

use App\Actions\Digibee\RunPipelineTestSuite;
use App\Actions\Flowspec\BuildPipelineTestMatrix;
use App\Enums\DigibeeTriggerAuth;
use App\Support\Digibee\DigibeeDesignClient;
use App\Support\Digibee\Testing\EndpointCredential;
use App\Support\Digibee\Testing\SuiteRun;
use Illuminate\Console\Command;

/**
 * Runs the synthetic battery against a DEPLOYED pipeline.
 *
 * The runner had no caller for a while: the matrix was derivable, the
 * evaluation was written, the deploy reported a URL, and nothing in the app
 * put the three together — so a battery could be read on screen and never
 * fired. This is that caller, and it is the last piece before block F, which
 * differs from it only in who reads the failures.
 *
 * **The endpoint credential is asked for, never stored.** A deployed
 * pipeline's consumer credential belongs to whoever owns that integration, so
 * it is prompted for with hidden input rather than taken from a flag (shell
 * history, `ps`) or from configuration. Passing `--auth=none` is how you say
 * out loud that the endpoint is open.
 */
class TestPipelineCommand extends Command
{
    protected $signature = 'digibee:pipeline:test
        {name : The pipeline name in the realm}
        {--environment=test : Which environment to call (must be allowed in config)}
        {--auth=basic : basic|key|jwt|none — how the endpoint authenticates its callers}
        {--key-header=x-api-key : Header name for --auth=key (the tenant uses several spellings)}';

    protected $description = 'Run the derived test battery against a deployed pipeline';

    public function handle(
        DigibeeDesignClient $client,
        BuildPipelineTestMatrix $matrix,
        RunPipelineTestSuite $runner,
    ): int {
        $name = (string) $this->argument('name');
        $environment = (string) $this->option('environment');

        $pipeline = $client->latestByName($name);

        if ($pipeline === null) {
            $this->components->error("Nenhum pipeline chamado \"{$name}\" existe no realm.");

            return self::FAILURE;
        }

        // The whole document, so the matrix can read the trigger's declared
        // response type as well as the flow's own terminal steps.
        $suite = $matrix->handle($client->pipeline((string) $pipeline['id']), $name, $environment);

        $deployment = $client->deployments($environment, $name)[0] ?? null;
        $endpoint = $deployment?->endpoint();

        if ($deployment === null) {
            $this->components->error("\"{$name}\" não está implantado em {$environment} — não há o que chamar.");

            return self::FAILURE;
        }

        if ($endpoint === null) {
            $this->components->warn(
                'O deployment não reporta endpoint (o pipeline não tem trigger web?). '
                . 'A URL será composta a partir do ambiente.'
            );
        }

        $credential = $this->credential();

        if ($credential === false) {
            return self::FAILURE;
        }

        $run = $runner->handle($suite, $credential, $endpoint);

        $this->report($run);

        return $run->passed() ? self::SUCCESS : self::FAILURE;
    }

    /** @return EndpointCredential|null|false false = the options are wrong, stop */
    private function credential(): EndpointCredential|null|false
    {
        $auth = DigibeeTriggerAuth::tryFrom((string) $this->option('auth'));

        if ($auth === null) {
            $this->components->error(
                '--auth inválido. Válidos: ' . implode(', ', array_column(DigibeeTriggerAuth::cases(), 'value')) . '.'
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

    private function report(SuiteRun $run): void
    {
        $tally = $run->tally();

        $this->newLine();
        $this->table(['', ''], [
            ['pipeline', $run->suite->pipelineName],
            ['url', $run->url],
            ['autenticado', $run->authenticated ? 'sim' : 'não'],
            ['casos', "{$tally['passed']} passaram, {$tally['failed']} falharam, {$tally['skipped']} não enviados"],
        ]);

        foreach ($run->results as $result) {
            $this->line(sprintf(
                '  %s %s <fg=gray>(%d)</>',
                $result->passed() ? '<fg=green>✓</>' : '<fg=red>✗</>',
                $result->case->name,
                $result->status,
            ));
        }

        foreach ($run->skipped as $case) {
            $this->line("  <fg=yellow>–</> {$case->name}: {$case->blocked}");
        }

        foreach ($run->failures() as $failure) {
            $this->line("  <fg=red>x</> {$failure}");
        }

        $this->newLine();

        // A wall of 401s looks exactly like a pipeline that rejects
        // everything, and saying which it is costs one line.
        if ($run->refusedForCredentials()) {
            $this->components->error(
                'Todos os casos voltaram 401/403 e nenhuma credencial foi passada — '
                . 'isso é o endpoint recusando na porta, não o pipeline falhando. Use --auth.'
            );

            return;
        }

        $run->passed()
            ? $this->components->info('Bateria verde.')
            : $this->components->warn("{$tally['failed']} caso(s) falharam.");
    }
}
