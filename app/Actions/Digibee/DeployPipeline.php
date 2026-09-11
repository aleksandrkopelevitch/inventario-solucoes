<?php

namespace App\Actions\Digibee;

use App\Enums\DeploymentStatus;
use App\Exceptions\DigibeeApiException;
use App\Support\Digibee\DeploymentReport;
use App\Support\Digibee\DigibeeAuthResolver;
use App\Support\Digibee\DigibeeDesignClient;
use Illuminate\Support\Sleep;

/**
 * Deploys a pipeline to an environment and waits for the platform to settle —
 * block D, and the step that turns a written flowSpec into something the test
 * matrix can call.
 *
 * Four properties, and the first two are the guardrails the spec's §5 asked
 * for in the wrong place (it worried about DELETE; the destructive verb here
 * is a deploy that reaches real traffic):
 *
 * - **The environment must be in `deployable_environments`.** Configuration,
 *   not an argument the agent chooses, so opening production is a deliberate
 *   act by a person editing config.
 * - **The TOKEN is asked first.** A scoped digibeectl token carries its ACL in
 *   the JWT, environment scoping included (`DEPLOYMENT:CREATE{ENV=TEST}`), so
 *   "this credential may not deploy there" is answerable before the request
 *   rather than as a 403 after it. An interactive session declares no roles
 *   and is left to the platform to judge.
 * - **Polling has a ceiling and reports what it saw.** A deploy that never
 *   settles is a third outcome, distinct from refused and from broken, and
 *   collapsing it into either is how a correction loop starts rewriting a
 *   pipeline that was merely slow.
 * - **The endpoint comes from the PLATFORM.** `deploymentStatus.trigger`
 *   carries the URL it assigned; composing one from the environment map is the
 *   fallback, not the source.
 */
class DeployPipeline
{
    /** How often to ask, in seconds. The platform's own poller uses 3. */
    private const POLL_SECONDS = 3;

    public function __construct(
        private readonly DigibeeDesignClient $client,
        private readonly DigibeeAuthResolver $auth,
    ) {}

    public function handle(
        string $pipelineName,
        string $environment = 'test',
        string $size = 'SMALL',
        bool $redeploy = true,
        int $timeoutSeconds = 300,
        bool $dryRun = false,
    ): DeploymentReport {
        $allowed = (array) config('services.digibee.design.deployable_environments');

        if (! in_array($environment, $allowed, true)) {
            throw DigibeeApiException::refusedDeployEnvironment($environment, $allowed);
        }

        $warnings = [];
        $denial = $this->tokenDenial($environment, $redeploy);

        if ($denial !== null) {
            return new DeploymentReport(
                pipelineName: $pipelineName,
                environment: $environment,
                errors: [$denial],
            );
        }

        $pipeline = $this->client->latestByName($pipelineName);

        if ($pipeline === null) {
            return new DeploymentReport(
                pipelineName: $pipelineName,
                environment: $environment,
                errors: ["Nenhum pipeline chamado \"{$pipelineName}\" existe no realm."],
            );
        }

        $pipelineId = (string) ($pipeline['id'] ?? '');

        // Only a RELEASED version deploys. All 111 deployments in `test` are
        // v1, no pipeline lists a v0, and a v0.0 pipeline answers this route
        // with 404 "No such entity" — which reads as a wrong id and is not.
        // Versioning happens in the canvas; no API route for it has been found.
        if ((int) ($pipeline['versionMajor'] ?? 0) < 1) {
            return new DeploymentReport(
                pipelineName: $pipelineName,
                environment: $environment,
                pipelineId: $pipelineId,
                errors: ['O pipeline está em v0.0 — um rascunho nunca implantado não tem versão publicada, '
                    . 'e a plataforma responde 404 "No such entity" a isso. Publique uma versão no canvas primeiro; '
                    . 'os 111 deployments de `test` são todos v1.'],
            );
        }

        if (($pipeline['triggerSpec'] ?? []) === []) {
            $warnings[] = 'O pipeline não tem triggerSpec: ele sobe, mas não ganha URL — '
                . 'a bateria de testes não vai ter o que chamar.';
        }

        $existing = $this->client->deployments($environment, $pipelineName);

        if ($existing !== [] && ! $redeploy) {
            $warnings[] = 'Já existe deployment desse pipeline no ambiente, e o redeploy não foi pedido.';
        }

        $payload = $this->payload($pipelineId, $size, $redeploy && $existing !== []);

        if ($dryRun) {
            // `$existing[0]?->` does NOT guard a missing index — the null-safe
            // operator only guards a null value — so a pipeline nobody has
            // deployed yet raised "Undefined array key 0" on the one path that
            // exists to be safe.
            $current = $existing[0] ?? null;

            return new DeploymentReport(
                pipelineName: $pipelineName,
                environment: $environment,
                pipelineId: $pipelineId,
                deploymentId: $current?->id(),
                status: $current?->status() ?? DeploymentStatus::Unknown,
                endpoint: $current?->endpoint(),
                warnings: [...$warnings, 'Dry run: o corpo montado tem as chaves '
                    . implode(', ', array_keys($payload)) . '.'],
            );
        }

        $this->client->deploy($payload, $environment);

        return $this->awaitSettled($pipelineName, $environment, $pipelineId, $timeoutSeconds, $warnings);
    }

    /**
     * The request body.
     *
     * The pipeline is named by `id`, NOT by `pipelineId` — probed on
     * 2026-09-11, and the two answer differently: `id` makes the handler look
     * something up (404 "No such entity" for a pipeline with no released
     * version) while `pipelineId` leaves it holding nothing (500 "The given id
     * must not be null"). The remaining keys are `digibeectl create
     * deployment`'s own flags, accepted structurally by the handler.
     *
     * The environment is deliberately absent — it goes in the query string,
     * and putting it here is what produced three identical 403s before anyone
     * realised the denial was about a field the server never read
     * (`DigibeeDesignClient::deploy()` has the full account).
     *
     * @return array<string, mixed>
     */
    private function payload(string $pipelineId, string $size, bool $redeploy): array
    {
        return [
            'id'           => $pipelineId,
            'pipelineSize' => strtoupper($size),
            'redeploy'     => $redeploy,
        ];
    }

    /**
     * Why this credential cannot deploy there, read off the token's own ACL —
     * or null when it can, or when the credential declares no roles at all
     * (an interactive session, judged by the platform).
     */
    private function tokenDenial(string $environment, bool $redeploy): ?string
    {
        $roles = $this->auth->credentials()->roles();

        if ($roles === []) {
            return null;
        }

        $wanted = strtoupper($environment);
        $grants = ['DEPLOYMENT:CREATE', "DEPLOYMENT:CREATE{ENV={$wanted}}"];

        if ($redeploy) {
            $grants[] = 'DEPLOYMENT:CREATE:REDEPLOY';
            $grants[] = "DEPLOYMENT:CREATE:REDEPLOY{ENV={$wanted}}";
        }

        foreach ($grants as $grant) {
            if (in_array($grant, $roles, true)) {
                return null;
            }
        }

        return "O token não declara permissão de deploy em \"{$environment}\". "
            . 'Ele tem: ' . implode(', ', $roles) . '. '
            . 'Isso é lido do próprio token, antes da chamada — a plataforma responderia 403.';
    }

    /**
     * @param  list<string>  $warnings
     */
    private function awaitSettled(
        string $pipelineName,
        string $environment,
        string $pipelineId,
        int $timeoutSeconds,
        array $warnings,
    ): DeploymentReport {
        $waited = 0;
        $deployment = null;

        while (true) {
            $found = $this->client->deployments($environment, $pipelineName);
            $deployment = $found[0] ?? null;

            if ($deployment?->status()->settled()) {
                break;
            }

            if ($waited >= $timeoutSeconds) {
                return new DeploymentReport(
                    pipelineName: $pipelineName,
                    environment: $environment,
                    pipelineId: $pipelineId,
                    deploymentId: $deployment?->id(),
                    status: $deployment?->status() ?? DeploymentStatus::Unknown,
                    endpoint: $deployment?->endpoint(),
                    engine: $deployment?->engine() ?? ['replicas' => null, 'errors' => 0, 'oom' => 0, 'lastError' => null],
                    deployed: true,
                    waitedSeconds: $waited,
                    warnings: [...$warnings, "O deployment não estabilizou em {$timeoutSeconds}s — "
                        . 'está no ar mas ainda não é evidência sobre o flowSpec.'],
                );
            }

            Sleep::for(self::POLL_SECONDS)->seconds();
            $waited += self::POLL_SECONDS;
        }

        $engine = $deployment->engine();

        if ($engine['lastError'] !== null) {
            $warnings[] = "O engine reporta um último erro: {$engine['lastError']}";
        }

        return new DeploymentReport(
            pipelineName: $pipelineName,
            environment: $environment,
            pipelineId: $pipelineId,
            deploymentId: $deployment->id(),
            status: $deployment->status(),
            endpoint: $deployment->endpoint(),
            engine: $engine,
            deployed: true,
            waitedSeconds: $waited,
            errors: $deployment->status()->healthy()
                ? []
                : ["O deployment subiu com status {$deployment->status()->label()}."],
            warnings: $warnings,
        );
    }
}
