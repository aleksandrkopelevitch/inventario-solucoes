<?php

namespace App\Actions\Digibee;

use App\Enums\FlowspecTarget;
use App\Enums\HealingVerdict;
use App\Services\Flowspec\DigibeeFlowspecNormalizer;
use App\Support\Digibee\DeployPermission;
use App\Support\Digibee\DigibeeAuthResolver;
use App\Support\Digibee\DigibeeDesignClient;
use App\Support\Digibee\Healing\HealingReport;
use App\Support\Digibee\PromotionReport;

/**
 * Bloco G — the gate between `test` and production.
 *
 * It is the verb this whole lifecycle exists to guard. `DELETE /pipelines` is
 * what the original spec worried about; the destructive operation here is
 * **deploying to `prod`**, because promotion is what reaches real traffic —
 * and the platform has no delete anyway.
 *
 * So this action is mostly refusals, and it is written to be read when it
 * refuses. Every condition is checked and EVERY failure is reported, not just
 * the first: somebody asking "why is this not in production" deserves the
 * whole list rather than a queue of one-at-a-time discoveries, each costing a
 * round trip.
 *
 * The conditions, and why each is not redundant:
 *
 * 1. **The target must be in `deployable_environments`.** Configuration, never
 *    an argument — opening production is an explicit act by a person. Today
 *    that list holds only `test`, so this gate refuses every promotion, which
 *    is the correct behaviour and not a stub.
 * 2. **The target must differ from the environment the evidence came from.**
 *    Promoting `test` to `test` is not a promotion, and it would let a green
 *    run authorise a redeploy of itself.
 * 3. **The evidence must be GREEN**, which in this app means more than an
 *    empty failure list: the happy path has to have RUN and passed
 *    (`HealingVerdict::Green` already encodes that). A tally is not a verdict —
 *    the whole battery exists because "three negative cases passed" once got
 *    reported as a working pipeline.
 * 4. **The token must declare deploy permission for the target**, read off its
 *    own JWT before the platform is asked. Ours holds
 *    `DEPLOYMENT:CREATE{ENV=TEST}` and nothing for prod.
 * 5. **What is stored must be what was tested.** This is the check the others
 *    cannot stand in for: between the green run and the promotion, somebody
 *    can open the canvas and change the pipeline. Promoting on the strength of
 *    a battery that ran against different bytes is precisely the failure a
 *    gate exists to prevent, and nothing else here would notice.
 *
 * **What it deliberately does NOT do is test production.** Firing the battery
 * at prod after promoting would send malformed payloads at real traffic —
 * `RunPipelineTestSuite` is hostile traffic by design, which is why it refuses
 * any environment outside `deployable_environments` in the first place. The
 * evidence for a promotion comes from `test`; production gets the deploy and
 * nothing else.
 */
class PromotePipeline
{
    public function __construct(
        private readonly DigibeeDesignClient $client,
        private readonly DigibeeAuthResolver $auth,
        private readonly DigibeeFlowspecNormalizer $normalizer,
        private readonly DeployPipeline $deploy,
    ) {}

    public function handle(
        HealingReport $evidence,
        string $toEnvironment = 'prod',
        string $size = 'SMALL',
        bool $dryRun = false,
    ): PromotionReport {
        $refusals = [];
        $warnings = [];

        $allowed = (array) config('services.digibee.design.deployable_environments');

        if (! in_array($toEnvironment, $allowed, true)) {
            $refusals[] = "\"{$toEnvironment}\" não está em deployable_environments (" . implode(', ', $allowed) . '). '
                . 'Abrir um ambiente é uma edição de configuração feita por uma pessoa, não um argumento.';
        }

        if ($toEnvironment === $evidence->environment) {
            $refusals[] = "A evidência veio de \"{$evidence->environment}\", que é o próprio destino — "
                . 'isso seria um redeploy autorizado por si mesmo, não uma promoção.';
        }

        if ($evidence->verdict !== HealingVerdict::Green) {
            $refusals[] = 'A evidência não é verde: ' . $evidence->verdict->label() . '.'
                . ($evidence->verdict->judgedThePipeline()
                    ? ''
                    : ' E ela não chega a ser uma conclusão sobre o pipeline.');
        }

        $denial = DeployPermission::denial($this->auth->credentials()->roles(), $toEnvironment);

        if ($denial !== null) {
            $refusals[] = $denial;
        }

        $drift = $this->drift($evidence);

        if ($drift !== null) {
            $refusals[] = $drift;
        }

        if ($refusals !== []) {
            return new PromotionReport(
                pipelineName: $evidence->pipelineName,
                fromEnvironment: $evidence->environment,
                toEnvironment: $toEnvironment,
                refusals: $refusals,
                warnings: $warnings,
            );
        }

        if ($dryRun) {
            return new PromotionReport(
                pipelineName: $evidence->pipelineName,
                fromEnvironment: $evidence->environment,
                toEnvironment: $toEnvironment,
                warnings: [...$warnings, 'Dry run: nada foi promovido. Todas as condições passaram.'],
            );
        }

        $deployment = $this->deploy->handle(
            pipelineName: $evidence->pipelineName,
            environment: $toEnvironment,
            size: $size,
        );

        return new PromotionReport(
            pipelineName: $evidence->pipelineName,
            fromEnvironment: $evidence->environment,
            toEnvironment: $toEnvironment,
            promoted: $deployment->live(),
            refusals: $deployment->errors,
            deployment: $deployment,
            warnings: [
                ...$warnings,
                ...$deployment->warnings,
                // Said on every promotion, because it is the one thing that
                // cannot be undone from here: this platform has no delete, and
                // the scoped token holds no DEPLOYMENT:DELETE either.
                "Promovido para \"{$toEnvironment}\": nada aqui remove um deployment — só o canvas.",
            ],
        );
    }

    /**
     * Whether the pipeline stored right now is still the one the evidence
     * tested.
     *
     * The comparison is on `flowSpec` alone and against the PLATFORM shape,
     * because that is the only common ground: the tested document is what the
     * generator emitted (rooted at `disconnected-root:<uuid>`, carrying a
     * canvas `meta`) while the stored one roots at `start` and has no `meta` —
     * `IngestFlowspec` converts on the way in, so the gate normalizes the same
     * way rather than inventing a second notion of "the same pipeline".
     *
     * Keys are sorted before encoding: a canvas that rewrote the document
     * without changing it should not read as drift, and two keys swapped is
     * not a different pipeline.
     */
    private function drift(HealingReport $evidence): ?string
    {
        if ($evidence->document === null) {
            return 'A evidência não carrega o documento que foi testado, então não dá para '
                . 'afirmar que é ele que está no ar.';
        }

        $stored = $this->client->latestByName($evidence->pipelineName);

        if ($stored === null) {
            return "Nenhum pipeline chamado \"{$evidence->pipelineName}\" existe no realm.";
        }

        $tested = $this->normalizer->normalize($evidence->document, FlowspecTarget::Platform)->document;

        if ($this->canonical($tested['flowSpec'] ?? []) === $this->canonical($stored['flowSpec'] ?? [])) {
            return null;
        }

        return 'O pipeline armazenado não é mais o que foi testado — alguém o alterou depois da '
            . 'bateria verde (o canvas escreve direto). Promover agora colocaria em produção bytes '
            . 'que nada testou.';
    }

    /** @param array<array-key, mixed> $value */
    private function canonical(array $value): string
    {
        $sort = function (array $node) use (&$sort): array {
            if (! array_is_list($node)) {
                ksort($node);
            }

            foreach ($node as $key => $item) {
                if (is_array($item)) {
                    $node[$key] = $sort($item);
                }
            }

            return $node;
        };

        return (string) json_encode($sort($value));
    }
}
