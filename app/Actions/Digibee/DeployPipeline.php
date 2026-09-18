<?php

namespace App\Actions\Digibee;

use App\Enums\DeploymentStatus;
use App\Exceptions\DigibeeApiException;
use App\Support\Digibee\Deployment;
use App\Support\Digibee\DeploymentReport;
use App\Support\Digibee\DeployPermission;
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

        // A v0 row IS deployable — the canvas deployed one while this code
        // was refusing to. An OLD version row deploys too: v0.2 of `apla-probe`
        // was accepted with a deployment id of its own while v1.0 was the
        // latest (2026-09-14), so the 404 "No such entity" that this comment
        // once blamed on the version was the `id`/`pipelineId` key mixup the
        // deploy body section had already settled. latestByName() stays the
        // resolution because the latest is what a lifecycle deploy means, not
        // because the platform refuses the others.

        // A REFUSAL, not a warning. This said "it goes up, it just gets no URL"
        // and that was wrong: the platform answers
        //
        //     500 Could not redeploy this pipeline due to an invalid trigger
        //     spec - missing type
        //
        // which reaches the operator as a `DigibeeApiException` naming a `type`
        // field, in a spec that does not exist at all — every APLA run against
        // a pipeline created without a trigger died there, twelve times, with
        // nothing in the message pointing at the cause. Stopping here costs the
        // run nothing it would not have lost anyway, and says what to supply.
        if (($pipeline['triggerSpec'] ?? []) === []) {
            return new DeploymentReport(
                pipelineName: $pipelineName,
                environment: $environment,
                pipelineId: $pipelineId,
                errors: ['O pipeline não tem gatilho (triggerSpec vazio), e a Digibee recusa publicar '
                    . 'assim — o erro que ela devolve ("invalid trigger spec - missing type") fala de '
                    . 'um campo que não existe. Escolha o tipo de gatilho antes de executar: um REST/HTTP '
                    . 'se resolve sozinho, um agendamento precisa do cron e um evento precisa do nome.'],
                warnings: $warnings,
            );
        }

        $existing = $this->client->deployments($environment, $pipelineName);

        if ($existing !== [] && ! $redeploy) {
            $warnings[] = 'Já existe deployment desse pipeline no ambiente, e o redeploy não foi pedido.';
        }

        [$configurationId, $refusal] = $this->configurationFor(
            $this->client->configurations($pipelineId),
            $size,
            $environment,
        );

        if ($refusal !== null) {
            return new DeploymentReport(
                pipelineName: $pipelineName,
                environment: $environment,
                pipelineId: $pipelineId,
                errors: [$refusal],
                warnings: $warnings,
            );
        }

        $payload = $this->payload($pipelineId, (string) $configurationId);

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

        $created = $this->client->deploy($payload, $environment);

        // Watch the deployment this call CREATED, not whatever the listing
        // happens to put first. A pipeline can hold more than one deployment
        // row at a time — `apla-probe` holds two — and a redeploy lives beside
        // the deployment it is replacing while it starts, so `[0]` is an
        // arbitrary choice among rows with different statuses. Watching the
        // wrong one reports a healthy pipeline as broken, which for the
        // healing loop is the difference between stopping and rewriting
        // something that was fine.
        $deploymentId = is_string($created['id'] ?? null) ? $created['id'] : null;

        return $this->awaitSettled($pipelineName, $environment, $pipelineId, $timeoutSeconds, $warnings, $deploymentId);
    }

    /**
     * The request body: `{pipelineId, runtimeConfigurationId}`.
     *
     * The environment is deliberately absent — it goes in the query string,
     * and putting it here is what produced three identical 403s before anyone
     * realised the denial was about a field the server never read
     * (`DigibeeDesignClient::deploy()` has the full account, including how the
     * second id was finally found).
     *
     * @return array<string, mixed>
     */
    private function payload(string $pipelineId, string $configurationId): array
    {
        return [
            'pipelineId'             => $pipelineId,
            'runtimeConfigurationId' => $configurationId,
        ];
    }

    /**
     * The configuration to deploy with, chosen by SIZE and ENVIRONMENT.
     *
     * **This is the second place production can be reached**, and it is less
     * obvious than the first. A pipeline has six configurations — three sizes
     * times `test` and `prod` — and the deploy names one by id. The
     * environment in the query string says where the request is aimed; the
     * configuration says which settings it lands with, and sending `prod`'s id
     * would at best be incoherent and at worst deploy production settings from
     * a request that looked like a test. So the match must be on both, and a
     * failure to find exactly one is a refusal rather than a first-one-wins.
     *
     * @param  list<array<string, mixed>>  $configurations
     * @return array{0: string|null, 1: string|null} the id, or the reason there is none
     */
    private function configurationFor(array $configurations, string $size, string $environment): array
    {
        $wanted = strtolower($size);

        $matching = array_values(array_filter(
            $configurations,
            fn (array $c) => str_starts_with(strtolower((string) ($c['name'] ?? '')), $wanted . '-')
                && strtolower((string) ($c['environment']['name'] ?? '')) === strtolower($environment),
        ));

        if ($matching === []) {
            $available = implode(', ', array_map(
                fn (array $c) => ($c['name'] ?? '?') . '@' . ($c['environment']['name'] ?? '?'),
                $configurations,
            ));

            return [null, "Nenhuma configuração {$wanted} para o ambiente \"{$environment}\". Existem: "
                . ($available ?: 'nenhuma') . '.'];
        }

        return [(string) ($matching[0]['id'] ?? ''), null];
    }

    /**
     * Why this credential cannot deploy there, read off the token's own ACL.
     *
     * The rule itself lives in `DeployPermission` because the promotion gate
     * asks it one step earlier — a gate that only learns the answer by
     * attempting the deploy is not a gate.
     */
    private function tokenDenial(string $environment, bool $redeploy): ?string
    {
        return DeployPermission::denial($this->auth->credentials()->roles(), $environment, $redeploy);
    }

    /**
     * The deployment this run is watching: the one the POST reported, or the
     * first row when it reported none.
     *
     * The fallback is not a shrug — a deployment takes a moment to appear in
     * the listing, and until it does there is nothing to match, so `[0]` is
     * the only answer available. What the id buys is the case where several
     * rows DO exist: the row this call created, rather than a neighbour that
     * settled long ago or a predecessor still being replaced.
     *
     * @param  list<Deployment>  $found
     */
    private function watched(array $found, ?string $deploymentId): ?Deployment
    {
        if ($deploymentId !== null) {
            foreach ($found as $deployment) {
                if ($deployment->id() === $deploymentId) {
                    return $deployment;
                }
            }
        }

        return $found[0] ?? null;
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
        ?string $deploymentId = null,
    ): DeploymentReport {
        $waited = 0;
        $deployment = null;

        while (true) {
            $found = $this->client->deployments($environment, $pipelineName);
            $deployment = $this->watched($found, $deploymentId);

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
