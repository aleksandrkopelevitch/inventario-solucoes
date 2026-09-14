<?php

namespace App\Jobs;

use App\Actions\Digibee\AssessPromotion;
use App\Enums\PipelineRunStatus;
use App\Models\PipelineRun;
use App\Services\Digibee\PipelineHealingService;
use App\Support\Digibee\Healing\HealingRound;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Queue\Middleware\WithoutOverlapping;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * Runs the write → deploy → test → correct loop for a `PipelineRun`, appending
 * each round as it finishes so the browser can watch.
 *
 * **This is the first thing in the app that reaches the Digibee realm from a
 * web request**, and the two ceilings below are what keep that defensible.
 */
class RunPipelineLifecycle implements ShouldQueue
{
    use Queueable;

    /**
     * Sized against a chain of three ceilings this job sits under, none of
     * which is ours to widen casually:
     *
     * 1. `retry_after` (900s) — outlive it and ANOTHER worker starts the same
     *    job while this one runs, which here means a second set of real
     *    deployments interleaved with the first.
     * 2. The worker's own `--timeout=660`
     *    (`deploy/supervisor/isol-queue.conf`) — it kills the job there
     *    whatever this property says.
     * 3. `stopwaitsecs=660` — a deploy landing mid-run SIGKILLs the worker
     *    past that, so no `failed()` runs and the row would sit `running`
     *    until the staleness reaper catches it.
     *
     * 600 leaves margin under all three, and `QueueConfigurationTest` is what
     * keeps this honest: it compares the slowest job's timeout against the
     * supervisor conf, in the one place those two languages meet.
     */
    public int $timeout = 600;

    /**
     * What one round may spend waiting for the platform, and how many rounds
     * the queued path allows.
     *
     * Fewer rounds with more patience each, deliberately: an `Unsettled`
     * verdict wastes a whole round — it deploys and learns nothing — and
     * deploys really do vary, measured from 12s (`apla-boot-01`) to past 300s
     * (`apla-draft-01`) on the same realm. Worst case here is 2 × (240 + the
     * battery and the model call) ≈ 560s, inside the 600 above.
     *
     * The console keeps `max_healing_rounds` and the deploy action's own 300s,
     * because nothing there is racing a queue.
     */
    private const DEPLOY_WAIT_SECONDS = 240;

    private const MAX_ROUNDS = 2;

    /** Sized for WithoutOverlapping releases, exactly as GenerateFlowspecReply is. */
    public int $tries = 25;

    /** Real failures stop here; a release does not count as one. */
    public int $maxExceptions = 2;

    /** @return array<int, int> */
    public function backoff(): array
    {
        return [30, 120];
    }

    public function __construct(public PipelineRun $run) {}

    /**
     * Serialized per PIPELINE, not per conversation.
     *
     * Two runs against the same pipeline would each write a document into it
     * and each deploy it, interleaved — the second overwriting what the first
     * is in the middle of testing, and both leaving deployments behind. The
     * conversation is the wrong key here: the same pipeline can legitimately
     * be the subject of two different chats.
     */
    public function middleware(): array
    {
        return [
            (new WithoutOverlapping($this->run->environment . ':' . $this->run->pipeline_name))
                ->expireAfter($this->timeout + 60)
                ->releaseAfter(60),
        ];
    }

    public function handle(PipelineHealingService $healing, AssessPromotion $assess): void
    {
        // A run someone already reaped (or that a second dispatch resurrected)
        // must not deploy anything: the row is the record of intent, and
        // anything settled has had its verdict read.
        if ($this->run->status->settled()) {
            return;
        }

        $this->run->loadMissing('message');

        $document = $this->run->message?->flow_spec;

        if (! is_array($document)) {
            $this->fail('A mensagem não carrega mais um flowSpec.');

            return;
        }

        $this->run->update([
            'status'     => PipelineRunStatus::Running,
            'started_at' => now(),
        ]);

        $report = $healing->heal(
            document: $document,
            pipelineName: $this->run->pipeline_name,
            environment: $this->run->environment,
            // Cast, because the column's default lives in the DATABASE: a row
            // created without the attribute carries null in memory until it is
            // refreshed, and the service's `bool` parameter refuses that.
            create: (bool) $this->run->creates,
            maxRounds: self::MAX_ROUNDS,
            onRound: fn (HealingRound $round) => $this->appendRound($round),
            deployTimeoutSeconds: self::DEPLOY_WAIT_SECONDS,
        );

        $readiness = $assess->handle($report);

        $this->run->update([
            'status'      => PipelineRunStatus::Done,
            'verdict'     => $report->verdict,
            'endpoint'    => $report->endpoint,
            'readiness'   => [
                'ready'    => $readiness->ready,
                'blockers' => $readiness->blockers,
                'version'  => $readiness->version,
                'notes'    => $readiness->notes,
            ],
            'finished_at' => now(),
        ]);
    }

    /**
     * One finished round, persisted immediately.
     *
     * Only what a screen needs: the round's verdict, how far it got, and the
     * evidence lines. The reports themselves are NOT stored — a
     * `DeploymentReport` carries the engine's raw log tail, which on a real
     * integration is whatever the pipeline was logging when it died.
     */
    private function appendRound(HealingRound $round): void
    {
        $this->run->update([
            'rounds' => [...$this->run->roundList(), [
                'round'    => $round->round,
                'verdict'  => $round->verdict->value,
                'label'    => $round->verdict->label(),
                'reached'  => $round->reached(),
                'evidence' => array_map(
                    fn (string $line) => mb_substr($line, 0, 500),
                    array_slice($round->evidence, 0, 10),
                ),
                'at' => now()->toIso8601String(),
            ]],
        ]);
    }

    public function failed(?Throwable $exception): void
    {
        if ($exception !== null) {
            Log::error('APLA: execução do ciclo de vida falhou', [
                'run_id'   => $this->run->id,
                'pipeline' => $this->run->pipeline_name,
                'exception' => $exception,
            ]);
        }

        // The type, never the message: a platform error can embed the request
        // URL and the realm, and this row is read in a browser.
        $this->fail($exception === null ? 'A execução falhou.' : 'A execução falhou (' . class_basename($exception) . ').');
    }

    private function fail(string $reason): void
    {
        $this->run->update([
            'status'      => PipelineRunStatus::Failed,
            'error'       => $reason,
            'finished_at' => now(),
        ]);
    }
}
