<?php

namespace App\Actions\Digibee;

use App\Enums\FlowspecTarget;
use App\Services\Flowspec\DigibeeFlowspecNormalizer;
use App\Services\Flowspec\DigibeeFlowspecValidator;
use App\Support\Digibee\DigibeeDesignClient;
use App\Support\Digibee\IngestionReport;
use App\Support\Digibee\TriggerSpec;

/**
 * Writes a generated `{meta, flowSpec}` into a real pipeline — the operation
 * `digibeectl` has no interface for, and the one the whole autonomous
 * lifecycle stands on.
 *
 * The sequence is short and every step of it is load-bearing:
 *
 * 1. **Normalize and validate for the PLATFORM target**, not the clipboard
 *    one. The document arrives rooted at `disconnected-root:<uuid>` with a
 *    canvas `meta`, because that is what the generator emits for pasting; a
 *    stored pipeline roots at `start` and has no `meta`.
 * 2. **Validate before writing, and refuse on any error.** The open question
 *    from Fase 1 is whether `POST /pipelines` validates a flowSpec at all —
 *    the upsert accepted a one-step document with no complaint — so an
 *    invalid document may well be stored happily and break only when somebody
 *    opens the canvas. That is precisely the failure DigibeeFlowspecValidator
 *    exists to catch first.
 * 3. **Resolve the pipeline by NAME**, never by listing everything: the
 *    listing embeds a whole flowSpec per item, 1801 of them.
 * 4. **Read the 34-key detail and send it back with the flowSpec replaced.**
 *    That exact shape is what A″ verified — a round trip in which the
 *    `flowSpec` comes back byte-identical.
 * 5. **Read it back and compare.** The verification is not ceremony: the API
 *    answers 200 for a create AND for an upsert on the same route, and it
 *    discards fields it does not recognise in silence (`projectId` twice
 *    over), so "answered 200" is not evidence that anything was written.
 *
 * What it will not do is invent a trigger for a pipeline that already has
 * one. A `triggerSpec` carries the endpoint's authentication mode and its
 * methods — things a person configured — so it is preserved unless replacing
 * it is asked for explicitly.
 */
class IngestFlowspec
{
    /**
     * Keys of `metadata` that are a stale reading of the flowSpec we just
     * replaced, and that we drop rather than carry.
     *
     * Both are safe to drop for the same measured reason: they are absent in
     * 161 of the 201 pipelines the tenant runs, so absence is the ordinary
     * state rather than a shape the canvas requires. `canvas` is the killer
     * of the two — it embeds the OLD node graph, trigger node included, so
     * carrying it forward would draw the previous pipeline over the new one.
     *
     * @var list<string>
     */
    private const STALE_METADATA = ['canvas', 'integrityHash'];

    /**
     * Derived counters that travel STALE, deliberately.
     *
     * Recomputing them means deciding what the platform means by a "step", a
     * "capsule" and a "subFlow" — three definitions nobody here has verified
     * — and a confidently wrong count is worse than an out-of-date one. They
     * are reported instead, so whoever reads the report knows the numbers on
     * the canvas describe the previous version until the platform recomputes
     * them.
     *
     * @var list<string>
     */
    private const DERIVED_COUNTERS = ['componentsCount', 'connectedOnFlowComponentsCount', 'usedComponents'];

    public function __construct(
        private readonly DigibeeDesignClient $client,
        private readonly DigibeeFlowspecNormalizer $normalizer,
        private readonly DigibeeFlowspecValidator $validator,
    ) {}

    /**
     * @param  array<string, mixed>  $document  a generated `{meta, flowSpec}`
     * @param  bool  $create  make the pipeline when the realm has no such name
     * @param  bool  $replaceTrigger  overwrite a `triggerSpec` the pipeline already has
     * @param  bool  $dryRun  resolve, validate and report — write nothing
     */
    public function handle(
        array $document,
        string $pipelineName,
        ?TriggerSpec $trigger = null,
        bool $create = false,
        bool $replaceTrigger = false,
        bool $dryRun = false,
    ): IngestionReport {
        $changes = [];
        $warnings = [];

        $normalization = $this->normalizer->normalize($document, FlowspecTarget::Platform);
        $prepared = $normalization->document;
        $changes = [...$changes, ...$normalization->fixes];

        // Counted before the two refusals below, so a report that writes
        // nothing still says what it was holding — "0 steps" beside a refusal
        // reads as an empty document rather than as a document not sent.
        $flowSpec = is_array($prepared['flowSpec'] ?? null) ? $prepared['flowSpec'] : [];
        $stepCount = array_sum(array_map(
            fn ($steps) => is_array($steps) ? count($steps) : 0,
            $flowSpec,
        ));

        $validation = $this->validator->validate($prepared, FlowspecTarget::Platform);

        if (! $validation->passes()) {
            return new IngestionReport(
                pipelineName: $pipelineName,
                stepCount: $stepCount,
                branchCount: count($flowSpec),
                changes: $changes,
                errors: $validation->errors,
            );
        }

        if ($trigger !== null && ! $trigger->usable()) {
            return new IngestionReport(
                pipelineName: $pipelineName,
                stepCount: $stepCount,
                branchCount: count($flowSpec),
                changes: $changes,
                trigger: $trigger,
                errors: array_map(
                    fn (string $missing) => "triggerSpec incompleto — {$missing}",
                    $trigger->missing,
                ),
            );
        }

        $existing = $this->client->latestByName($pipelineName);
        $created = false;

        if ($existing === null) {
            if (! $create) {
                return new IngestionReport(
                    pipelineName: $pipelineName,
                    stepCount: $stepCount,
                    branchCount: count($flowSpec),
                    changes: $changes,
                    trigger: $trigger,
                    errors: ["Nenhum pipeline chamado \"{$pipelineName}\" existe no realm. "
                        . 'Criar um é permanente: nada na plataforma apaga pipeline, então isso precisa ser pedido explicitamente.'],
                );
            }

            if ($dryRun) {
                return new IngestionReport(
                    pipelineName: $pipelineName,
                    stepCount: $stepCount,
                    branchCount: count($flowSpec),
                    changes: [...$changes, "Criaria o pipeline \"{$pipelineName}\" (v0.0, draft) e escreveria o flowSpec nele."],
                    warnings: [...$warnings, 'Um pipeline novo nasce no projeto `default`: a API aceita `projectId` e descarta em silêncio.'],
                    trigger: $trigger,
                );
            }

            $id = $this->client->create($pipelineName, 'Criado pelo APLA a partir de um flowSpec gerado.');
            $created = true;
            $changes[] = "Pipeline \"{$pipelineName}\" criado (id {$id}).";
            $warnings[] = 'O pipeline nasceu no projeto `default`: a API aceita `projectId` e descarta em silêncio.';
            $detail = $this->client->pipeline($id);
        } else {
            $id = (string) ($existing['id'] ?? '');
            $detail = $this->client->pipeline($id);
        }

        $versionMajor = (int) ($detail['versionMajor'] ?? 0);
        $versionMinor = (int) ($detail['versionMinor'] ?? 0);

        if (! $created && ($versionMajor > 0 || ($detail['draft'] ?? null) === false)) {
            // A″ verified the round trip against a v0.0 draft. What an upsert
            // does to a pipeline that has released versions — write a new
            // draft, or move the released one — was never observed, and the
            // difference matters to anything already deployed from it.
            $warnings[] = "O pipeline está em v{$versionMajor}.{$versionMinor}"
                . (($detail['draft'] ?? null) === false ? ' e não está em draft' : '')
                . '. O upsert foi verificado apenas contra um v0.0 em draft: '
                . 'confirme no canvas o que ele fez com a versão publicada.';
        }

        $payload = $detail;
        $payload['flowSpec'] = $flowSpec;
        $changes[] = "flowSpec substituído: {$stepCount} step(s) em " . count($flowSpec) . ' branch(es).';

        $triggerApplied = false;
        $currentTrigger = is_array($detail['triggerSpec'] ?? null) ? $detail['triggerSpec'] : [];

        if ($trigger !== null) {
            if ($currentTrigger === [] || $replaceTrigger) {
                $payload['triggerSpec'] = $trigger->toArray();
                $payload['triggerCategory'] = $trigger->kind->category();
                $triggerApplied = true;
                $changes[] = 'triggerSpec ' . ($currentTrigger === [] ? 'escrito' : 'substituído')
                    . ": {$trigger->kind->label()}, {$trigger->auth->label()}.";
                $warnings = [...$warnings, ...$trigger->assumptions];
            } else {
                $warnings[] = 'triggerSpec existente preservado: ele carrega o modo de autenticação e os '
                    . 'métodos que alguém configurou. Passe a substituição explicitamente para sobrescrever.';
            }
        } elseif ($currentTrigger === []) {
            $warnings[] = 'O pipeline não tem triggerSpec, e nenhum foi sintetizado: '
                . 'sem isso ele não tem URL nem modo de autenticação, e a bateria de testes não tem o que chamar.';
        }

        [$payload, $metadataChanges] = $this->cleanMetadata($payload);
        $changes = [...$changes, ...$metadataChanges];
        $warnings = [...$warnings, ...$this->staleCounterWarnings($payload)];

        if ($dryRun) {
            return new IngestionReport(
                pipelineName: $pipelineName,
                pipelineId: $id,
                created: false,
                wrote: false,
                stepCount: $stepCount,
                branchCount: count($flowSpec),
                versionMajor: $versionMajor,
                versionMinor: $versionMinor,
                trigger: $trigger,
                triggerApplied: $triggerApplied,
                changes: $changes,
                warnings: $warnings,
            );
        }

        $this->client->upsert($payload);

        // Read back and compare. `json_encode` of both sides is the same
        // comparison A″ made by hand, and the property it establishes is the
        // one that matters: what the platform stored is what we sent, key
        // order and unicode escaping aside.
        $readBack = $this->client->pipeline($id);
        $verified = json_encode($readBack['flowSpec'] ?? null) === json_encode($flowSpec);

        if (! $verified) {
            $warnings[] = 'O flowSpec relido NÃO é idêntico ao enviado. A rota respondeu 200, '
                . 'o que nesta API não é evidência de escrita: confira o pipeline no canvas.';
        }

        return new IngestionReport(
            pipelineName: $pipelineName,
            pipelineId: $id,
            created: $created,
            wrote: true,
            verified: $verified,
            stepCount: $stepCount,
            branchCount: count($flowSpec),
            versionMajor: $versionMajor,
            versionMinor: $versionMinor,
            trigger: $trigger,
            triggerApplied: $triggerApplied,
            changes: $changes,
            warnings: $warnings,
        );
    }

    /**
     * @param  array<string, mixed>  $payload
     * @return array{0: array<string, mixed>, 1: list<string>}
     */
    private function cleanMetadata(array $payload): array
    {
        if (! is_array($payload['metadata'] ?? null)) {
            return [$payload, []];
        }

        $changes = [];

        foreach (self::STALE_METADATA as $key) {
            if (array_key_exists($key, $payload['metadata'])) {
                unset($payload['metadata'][$key]);
                $changes[] = "`metadata.{$key}` removido: era uma leitura do flowSpec anterior "
                    . '(e 161 dos 201 pipelines do tenant não têm essa chave).';
            }
        }

        return [$payload, $changes];
    }

    /**
     * @param  array<string, mixed>  $payload
     * @return list<string>
     */
    private function staleCounterWarnings(array $payload): array
    {
        $stale = [];

        foreach (self::DERIVED_COUNTERS as $key) {
            if (is_array($payload['metadata'] ?? null) && array_key_exists($key, $payload['metadata'])) {
                $stale[] = "metadata.{$key}";
            }
        }

        if (is_array($payload['counters'] ?? null)) {
            $stale[] = 'counters';
        }

        return $stale === [] ? [] : [
            'Contadores derivados seguem descrevendo a versão anterior (' . implode(', ', $stale) . '): '
            . 'recalcular exigiria adivinhar o que a plataforma conta como step, capsule e subFlow.',
        ];
    }
}
