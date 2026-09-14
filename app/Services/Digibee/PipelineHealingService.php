<?php

namespace App\Services\Digibee;

use App\Actions\Digibee\DeployPipeline;
use App\Actions\Digibee\IngestFlowspec;
use App\Actions\Digibee\RunPipelineTestSuite;
use App\Actions\Flowspec\BuildPipelineTestMatrix;
use App\Enums\HealingVerdict;
use App\Exceptions\DigibeeApiException;
use App\Services\Flowspec\FlowspecPromptBuilder;
use App\Support\Digibee\DeploymentReport;
use App\Support\Digibee\Healing\HealingReport;
use App\Support\Digibee\Healing\HealingRound;
use App\Support\Digibee\Testing\EndpointCredential;
use App\Support\Digibee\Testing\SuiteRun;
use App\Support\Digibee\TriggerSpec;
use App\Support\Flowspec\FlowspecJson;
use Illuminate\Support\Facades\Log;
use Laravel\Ai\Responses\AgentResponse;

use function Laravel\Ai\agent;

/**
 * Bloco F — the correction loop that runs on RUNTIME evidence.
 *
 * `FlowspecGenerationService` already loops: generate, normalize, validate,
 * re-prompt with the concrete errors. This is the same shape with the signal
 * swapped, which is the one thing the roadmap said would change — there the
 * evidence is a rule the document broke before it left this app, here it is
 * what the platform and a real HTTP call said after it was written, deployed
 * and fired at.
 *
 * The loop is four steps and they are strictly ordered, because each one is
 * the other's precondition: write (`IngestFlowspec`), deploy
 * (`DeployPipeline`), test (`BuildPipelineTestMatrix` + `RunPipelineTestSuite`),
 * judge. Nothing is skipped and nothing runs out of order — a battery fired at
 * an address whose deploy was refused is how this feature produced its first
 * false green.
 *
 * **What earns a re-prompt is the whole design.** A round can end in nine
 * different ways (`App\Enums\HealingVerdict`) and only TWO of them hand the
 * model anything: cases that ran and failed, and a document that would not
 * ingest. Every other ending is a fact about the environment, the credential,
 * the platform or the clock, and re-prompting on one of those spends an
 * attempt asking a language model to fix something it cannot reach — while
 * leaving another deployment behind in a realm where nothing deletes a
 * pipeline and this token cannot delete a deployment. That is why the refusals
 * below are as load-bearing as the corrections:
 *
 * - **Refused** (environment not allowed, permission, no matching runtime
 *   configuration) — the request never reached a pipeline.
 * - **Unsettled** — the deploy did not settle inside the ceiling. Collapsing
 *   this into "broken" is exactly how a loop starts rewriting a pipeline that
 *   was merely slow.
 * - **NotAnswering** — every case came back 404. Nothing is there.
 * - **RefusedAtTheDoor** — every case came back 401/403 with no credential
 *   given. The door, not the pipeline.
 * - **Unproven** — nothing failed, but the happy path never ran, so nothing
 *   showed the pipeline works. There is no failure to correct, and asking for
 *   one anyway means asking the model to rewrite something that may be right.
 *
 * And one that is about the loop itself rather than the pipeline: **Stuck**,
 * when the model answers with no document or with the same document it was
 * just asked to fix. The next round would ingest the same bytes and collect
 * the same evidence, so it is a deploy spent to learn nothing.
 *
 * **The platform caps this loop at one deploy today, and that is measured.**
 * Deploying PUBLISHES a pipeline — `apla-boot-01` was created `draft: true`,
 * survived two upserts as a draft, and came out of its deploy `draft: false`,
 * after which every write answers `409 "You cannot update a pipeline that is
 * not on draft mode"`. So round two cannot write into what round one deployed,
 * and the run ends `NotWritable` rather than by exception. The way out is a
 * token that may call `POST /design/realms/{realm}/pipelines/{id}/draft` —
 * that route exists (it answers the design API's own
 * `403 INSUFFICIENT_PERMISSIONS`, while its neighbours answer the gateway's
 * generic 500) — so this is a permission to be granted, not code to be
 * written. The loop is built to use it the moment it is.
 */
class PipelineHealingService
{
    /**
     * How much of the engine's own log blob travels into the prompt.
     *
     * `deploymentStatus.last-error-message` is a rolling window of raw log
     * lines — stack traces, and whatever the pipeline happened to be logging
     * when it died, which on a real integration is somebody's payload. It is
     * also the only place the engine says WHY it refused to boot, so it cannot
     * simply be withheld. Bounded, and taken from the FRONT: the exception and
     * its first frames are the diagnosis; the tail is repetition.
     */
    private const ENGINE_ERROR_CHARS = 1200;

    public function __construct(
        private readonly IngestFlowspec $ingest,
        private readonly DeployPipeline $deploy,
        private readonly BuildPipelineTestMatrix $matrix,
        private readonly RunPipelineTestSuite $runner,
        private readonly FlowspecPromptBuilder $prompts,
    ) {}

    /**
     * @param  array<string, mixed>  $document  a generated `{meta, flowSpec}`
     * @param  EndpointCredential|null  $credential  what gets past the deployed
     *                                               pipeline's own trigger — never
     *                                               the design credential
     */
    /**
     * @param  (callable(HealingRound): void)|null  $onRound  called as each round
     *         finishes, before the next one starts
     */
    public function heal(
        array $document,
        string $pipelineName,
        string $environment = 'test',
        ?TriggerSpec $trigger = null,
        ?EndpointCredential $credential = null,
        bool $create = false,
        ?int $maxRounds = null,
        ?callable $onRound = null,
        ?int $deployTimeoutSeconds = null,
    ): HealingReport {
        $maxRounds = max(1, $maxRounds ?? (int) config('services.digibee.design.max_healing_rounds', 3));

        $rounds = [];
        $endpoint = null;

        for ($round = 1; $round <= $maxRounds; $round++) {
            $outcome = $this->round(
                $round, $document, $pipelineName, $environment,
                $trigger, $credential, $create, $deployTimeoutSeconds,
            );
            $rounds[] = $outcome;
            $endpoint = $outcome->deployment?->endpoint ?? $endpoint;

            // Reported as it happens, not at the end. A round is a real
            // deployment in a shared realm, and a screen that only resolves
            // minutes later leaves somebody watching a spinner while those
            // deployments occur. The callback runs BEFORE the model is asked
            // for a correction, which is the slowest part of a cycle.
            if ($onRound !== null) {
                $onRound($outcome);
            }

            // The pipeline creation is a first-round concern only: rounds two
            // and up are rewriting the pipeline round one created, and asking
            // for a create again would be asking for a second pipeline with
            // the same name — which this platform answers by making one.
            $create = false;

            if (! $outcome->correctable()) {
                return $this->report($pipelineName, $environment, $outcome->verdict, $rounds, $document, $endpoint);
            }

            if ($round === $maxRounds) {
                return $this->report($pipelineName, $environment, $outcome->verdict, $rounds, $document, $endpoint);
            }

            $corrected = $this->correct($document, $outcome->evidence, $pipelineName, $environment);

            if ($corrected === null || $this->sameDocument($corrected, $document)) {
                $stuck = new HealingRound(
                    round: $round + 1,
                    verdict: HealingVerdict::Stuck,
                    evidence: $outcome->evidence,
                );
                $rounds[] = $stuck;

                if ($onRound !== null) {
                    $onRound($stuck);
                }

                return $this->report($pipelineName, $environment, HealingVerdict::Stuck, $rounds, $document, $endpoint);
            }

            $document = $corrected;
        }

        // Unreachable: the loop returns from inside. Kept as a total function
        // rather than falling off the end with no report.
        return $this->report($pipelineName, $environment, HealingVerdict::Stuck, $rounds, $document, $endpoint);
    }

    /**
     * One write → deploy → test → judge cycle.
     *
     * @param  array<string, mixed>  $document
     */
    private function round(
        int $round,
        array $document,
        string $pipelineName,
        string $environment,
        ?TriggerSpec $trigger,
        ?EndpointCredential $credential,
        bool $create,
        ?int $deployTimeoutSeconds,
    ): HealingRound {
        try {
            $ingestion = $this->ingest->handle(
                document: $document,
                pipelineName: $pipelineName,
                trigger: $trigger,
                create: $create,
            );
        } catch (DigibeeApiException $e) {
            // A refusal from the platform is a ROUND ending, never the end of
            // the run by exception — and the one that actually happens is the
            // 409 below. A multi-round loop that aborts on the first platform
            // answer it did not expect reports nothing at all about the
            // rounds that already ran.
            return new HealingRound(
                round: $round,
                verdict: HealingVerdict::NotWritable,
                evidence: [$e->getMessage()],
            );
        }

        Log::info('APLA: rodada de cura — escrita', [
            'pipeline'  => $pipelineName,
            'round'     => $round,
            'confirmed' => $ingestion->confirmed(),
            'errors'    => count($ingestion->errors),
        ]);

        if (! $ingestion->confirmed()) {
            // A refused write is NOT automatically evidence about the document,
            // and treating it as such is how this loop spent a model call
            // asking for a fix to a flowSpec that was fine: the default
            // pipeline name on the new screen is a slug of the chat title,
            // which usually names no pipeline at all, and "no pipeline called X
            // exists in the realm" came back as something to correct.
            //
            // `IngestionReport::correctable()` is the discriminator, and only
            // OUR validation rejecting the flowSpec sets it. A missing
            // pipeline, or a triggerSpec the caller asked for without the cron
            // it needs, are facts about the request and the realm — the run
            // stops and says so.
            return new HealingRound(
                round: $round,
                verdict: $ingestion->correctable() ? HealingVerdict::NotIngested : HealingVerdict::NotWritable,
                ingestion: $ingestion,
                evidence: $ingestion->errors,
            );
        }

        // The wait is a parameter because the caller's own ceiling decides it.
        // A queued run must finish inside `retry_after` (900s): a job that
        // outlives it is RUN AGAIN by another worker while the first is still
        // going, which here means a second set of real deployments. The console
        // has no such ceiling and keeps the action's default.
        $deployment = $deployTimeoutSeconds === null
            ? $this->deploy->handle(pipelineName: $pipelineName, environment: $environment)
            : $this->deploy->handle(
                pipelineName: $pipelineName,
                environment: $environment,
                timeoutSeconds: $deployTimeoutSeconds,
            );

        Log::info('APLA: rodada de cura — deploy', [
            'pipeline' => $pipelineName,
            'round'    => $round,
            'status'   => $deployment->status->value,
            'deployed' => $deployment->deployed,
            'waited'   => $deployment->waitedSeconds,
        ]);

        if (! $deployment->ok()) {
            return new HealingRound($round, HealingVerdict::Refused, $ingestion, $deployment);
        }

        if (! $deployment->deployed || ! $deployment->status->settled()) {
            return new HealingRound($round, HealingVerdict::Unsettled, $ingestion, $deployment);
        }

        if (! $deployment->live()) {
            // Deployed, settled, and the engine says it is broken: this IS
            // evidence about the document, and the engine's own message is the
            // only thing that says why.
            return new HealingRound(
                round: $round,
                verdict: HealingVerdict::StillFailing,
                ingestion: $ingestion,
                deployment: $deployment,
                evidence: $this->engineEvidence($deployment),
            );
        }

        if (! $deployment->testable()) {
            return new HealingRound($round, HealingVerdict::Unsettled, $ingestion, $deployment);
        }

        $suite = $this->matrix->handle($document, $pipelineName, $environment);
        $run = $this->runner->handle($suite, $credential, $deployment->endpoint);

        Log::info('APLA: rodada de cura — bateria', [
            'pipeline' => $pipelineName,
            'round'    => $round,
            ...$run->tally(),
        ]);

        return new HealingRound(
            round: $round,
            verdict: $this->judge($run),
            ingestion: $ingestion,
            deployment: $deployment,
            run: $run,
            evidence: $this->suiteEvidence($run),
        );
    }

    /**
     * What a finished battery means — and the order matters, because the
     * disqualifying readings have to be asked BEFORE the tally.
     *
     * A wall of 404s and a wall of 401s both produce a tally that looks like
     * passes (a negative case expects "anything but a 5xx"), so asking
     * "did anything fail?" first answers green to a pipeline nobody reached.
     */
    private function judge(SuiteRun $run): HealingVerdict
    {
        return match (true) {
            $run->nothingAnswered()        => HealingVerdict::NotAnswering,
            $run->refusedForCredentials()  => HealingVerdict::RefusedAtTheDoor,
            $run->failed() !== []          => HealingVerdict::StillFailing,
            $run->passed() && $run->provenByHappyPath() => HealingVerdict::Green,
            default                        => HealingVerdict::Unproven,
        };
    }

    /** @return list<string> */
    private function engineEvidence(DeploymentReport $deployment): array
    {
        $lines = [sprintf(
            'O deploy completou mas o engine subiu com erro (%s, réplicas %s).',
            $deployment->status->label(),
            $deployment->engine['replicas'] ?? '?',
        )];

        $lastError = $deployment->engine['lastError'] ?? null;

        if (is_string($lastError) && trim($lastError) !== '') {
            $lines[] = 'O engine reportou: ' . mb_substr(trim($lastError), 0, self::ENGINE_ERROR_CHARS);
        }

        return $lines;
    }

    /**
     * One re-promptable line per failed case.
     *
     * `CaseResult::failures()` is already written to stand on its own — which
     * case, what was expected, what came back — so this only names the case
     * and passes them through. The BLOCKED cases are deliberately absent: a
     * case nobody could send says nothing about the pipeline, and a model
     * handed "the happy path did not run" will try to make it run by changing
     * the flowSpec.
     *
     * @return list<string>
     */
    private function suiteEvidence(SuiteRun $run): array
    {
        $lines = [];

        foreach ($run->failed() as $result) {
            foreach ($result->failures() as $failure) {
                $lines[] = "[{$result->case->name}] {$failure}";
            }
        }

        return $lines;
    }

    /**
     * Asks the model for a corrected document, and returns it only if it IS a
     * document.
     *
     * A reply with no `{meta, flowSpec}` in it is not a conversational turn to
     * preserve, the way it would be in the generation loop: the request here
     * was "here is a deployed pipeline and what it did wrong", so an answer
     * carrying no document is an answer that did not do the job.
     *
     * @param  array<string, mixed>  $document
     * @param  list<string>  $evidence
     * @return array<string, mixed>|null
     */
    private function correct(array $document, array $evidence, string $pipelineName, string $environment): ?array
    {
        $prompt = $this->prompts->runtimeCorrectionPrompt($document, $evidence, $pipelineName, $environment);

        // The prompt carries a whole pipeline document; only its size is
        // logged, for the same reason FlowspecGenerationService logs only
        // sizes — a flowSpec can hold an account label, a global name and
        // whatever the engine was logging when it died.
        Log::debug('APLA: prompt de correção por runtime', [
            'pipeline'     => $pipelineName,
            'prompt_chars' => mb_strlen($prompt),
            'evidence'     => count($evidence),
        ]);

        return FlowspecJson::documentIn($this->prompt($prompt)->text);
    }

    protected function prompt(string $prompt): AgentResponse
    {
        return agent(instructions: $this->prompts->systemPrompt())->prompt(
            $prompt,
            provider: config('services.flowspec.provider'),
            model: config('services.flowspec.model'),
            timeout: (int) config('services.flowspec.timeout'),
        );
    }

    /**
     * Whether the model handed back what it was given.
     *
     * Compared as the CANONICAL json of the whole document rather than by a
     * hash of the flowSpec alone: a correction that only touches `meta` is
     * still a change worth deploying, and a key order the model happened to
     * shuffle is not. `json_encode` preserves insertion order, so the arrays
     * are sorted first — otherwise the same pipeline with two keys swapped
     * reads as progress and buys another deploy.
     *
     * @param  array<string, mixed>  $a
     * @param  array<string, mixed>  $b
     */
    private function sameDocument(array $a, array $b): bool
    {
        return $this->canonical($a) === $this->canonical($b);
    }

    /** @param array<string, mixed> $value */
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

    /**
     * @param  list<HealingRound>  $rounds
     * @param  array<string, mixed>  $document
     */
    private function report(
        string $pipelineName,
        string $environment,
        HealingVerdict $verdict,
        array $rounds,
        array $document,
        ?string $endpoint,
    ): HealingReport {
        $warnings = [];

        if (! $verdict->judgedThePipeline()) {
            $warnings[] = 'Nada aqui é uma conclusão sobre o pipeline: ' . $verdict->label() . '.';
        }

        $deploys = count(array_filter($rounds, fn (HealingRound $r) => $r->deployment?->deployed === true));

        if ($deploys > 0) {
            // Said every time, not only on failure: this platform deletes
            // nothing, and the scoped token cannot delete a deployment either.
            $warnings[] = "Esta execução deixou {$deploys} implantação(ões) em `{$environment}` — "
                . 'só o canvas remove.';
        }

        return new HealingReport(
            pipelineName: $pipelineName,
            environment: $environment,
            verdict: $verdict,
            rounds: $rounds,
            document: $document,
            endpoint: $endpoint,
            warnings: $warnings,
        );
    }
}
