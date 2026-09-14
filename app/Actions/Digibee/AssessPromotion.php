<?php

namespace App\Actions\Digibee;

use App\Enums\FlowspecTarget;
use App\Enums\HealingVerdict;
use App\Services\Flowspec\DigibeeFlowspecNormalizer;
use App\Support\Digibee\DigibeeDesignClient;
use App\Support\Digibee\Healing\HealingReport;
use App\Support\Digibee\PromotionReadiness;

/**
 * Bloco G — the answer a person gets before promoting a pipeline by hand.
 *
 * **It does not promote, and that is the design rather than a limitation.**
 * The agent works in `test`; the promotion to production is a human clicking in
 * the Digibee panel. Handing this droplet — shared with two other apps — a
 * credential that could deploy to production would reverse the boundary the
 * whole feature is built on ("the artifact travels, the credential does not"),
 * in exchange for saving one click. So the useful thing this block can do is
 * not to act; it is to answer honestly whether acting is safe.
 *
 * Two conditions, and neither is ceremony:
 *
 * 1. **The evidence has to be GREEN**, which in this app means more than an
 *    empty failure list: the happy path has to have RUN and passed
 *    (`HealingVerdict::Green` encodes that). A tally is not a verdict — this
 *    whole battery exists because "three negative cases passed" was once
 *    reported as a working pipeline, against an endpoint nothing had reached.
 * 2. **What is stored has to be what was tested.** This is the one that earns
 *    its keep, and it earns MORE here than it did when this block deployed by
 *    itself: between the green run and a person clicking promote, the canvas
 *    can rewrite the pipeline, and the panel will happily promote whatever is
 *    there. Nothing else in the loop would notice.
 *
 * And it reports the VERSION, because the panel lists rows: a verdict that does
 * not name which row to promote is a verdict nobody can act on.
 */
class AssessPromotion
{
    public function __construct(
        private readonly DigibeeDesignClient $client,
        private readonly DigibeeFlowspecNormalizer $normalizer,
    ) {}

    public function handle(HealingReport $evidence): PromotionReadiness
    {
        $blockers = [];

        if ($evidence->verdict !== HealingVerdict::Green) {
            $blockers[] = 'A evidência não é verde: ' . $evidence->verdict->label() . '.'
                . ($evidence->verdict->judgedThePipeline()
                    ? ''
                    : ' E ela não chega a ser uma conclusão sobre o pipeline.');
        }

        $stored = $evidence->document === null
            ? null
            : $this->client->latestByName($evidence->pipelineName);

        if ($evidence->document === null) {
            $blockers[] = 'A evidência não carrega o documento que foi testado, então não dá para '
                . 'afirmar que é ele que está armazenado.';
        } elseif ($stored === null) {
            $blockers[] = "Nenhum pipeline chamado \"{$evidence->pipelineName}\" existe no realm.";
        } elseif (! $this->matches($evidence->document, $stored)) {
            $blockers[] = 'O pipeline armazenado não é mais o que foi testado — alguém o alterou depois '
                . 'da bateria verde (o canvas escreve direto). Promover agora colocaria em produção '
                . 'bytes que nada testou.';
        }

        $version = $stored === null
            ? null
            : 'v' . ($stored['versionMajor'] ?? '?') . '.' . ($stored['versionMinor'] ?? '?');

        return new PromotionReadiness(
            pipelineName: $evidence->pipelineName,
            testedIn: $evidence->environment,
            ready: $blockers === [],
            blockers: $blockers,
            version: $version,
            endpoint: $evidence->endpoint,
            notes: $blockers === []
                ? ['Promova pela Digibee: a versão acima foi testada em `' . $evidence->environment
                    . '` e é a que está armazenada agora. Este app não implanta em produção, por decisão.']
                : [],
        );
    }

    /**
     * Whether the stored pipeline is still the tested one.
     *
     * The comparison is on `flowSpec` and against the PLATFORM shape, the only
     * common ground: the tested document is what the generator emits (rooted at
     * `disconnected-root:<uuid>`, carrying a canvas `meta`) while the stored one
     * roots at `start` and has neither. `IngestFlowspec` converts on the way in,
     * so this normalizes the same way rather than inventing a second notion of
     * "the same pipeline" — comparing raw would block every correct promotion.
     *
     * Keys are sorted before encoding: two keys swapped is not a different
     * pipeline, and reporting it as drift would train somebody to ignore this.
     *
     * @param  array<string, mixed>  $tested
     * @param  array<string, mixed>  $stored
     */
    private function matches(array $tested, array $stored): bool
    {
        $prepared = $this->normalizer->normalize($tested, FlowspecTarget::Platform)->document;

        return $this->canonical($prepared['flowSpec'] ?? []) === $this->canonical($stored['flowSpec'] ?? []);
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
