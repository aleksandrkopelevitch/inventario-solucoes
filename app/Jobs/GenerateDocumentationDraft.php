<?php

namespace App\Jobs;

use App\Models\DocumentationAiGeneration;
use App\Services\Documentation\DocumentationDraftService;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Queue\Middleware\WithoutOverlapping;
use Throwable;

/**
 * Generates the "Assiste IA" draft for a page/integration. The UI doesn't wait:
 * docs-ai.js polls the status route until the record leaves `pending`. The
 * result (Markdown) is loaded into the editor for review. failed() marks the
 * record as `failed` so the polling never hangs.
 */
class GenerateDocumentationDraft implements ShouldQueue
{
    use Queueable;

    /** Well below the queue's retry_after (900s). */
    public int $timeout = 600;

    /**
     * Sized for the worst case of WithoutOverlapping: each "wait" (a new
     * request for the same target before this one finishes) goes back to the
     * queue via release() and consumes one attempt — not a real failure, just
     * queuing. A job ahead can hold the lock for up to $timeout (600s), and
     * the blocked one retries every releaseAfter (30s): 600/30 = 20 possible
     * releases, plus margin. REAL failures are bounded by $maxExceptions, not
     * this.
     */
    public int $tries = 25;

    /**
     * Real exceptions (API down, timeout) stop well before $tries — without
     * this, the high $tries above would mean 25 API calls during an outage.
     * WithoutOverlapping releases don't count as an exception.
     */
    public int $maxExceptions = 3;

    /** @return array<int, int> exponential backoff between real exceptions */
    public function backoff(): array
    {
        return [10, 30, 60];
    }

    public function __construct(public DocumentationAiGeneration $generation) {}

    /**
     * Serializes generation per target (page/integration): two requests in
     * quick succession for the same page can't run in parallel. The blocked
     * job goes back to the queue and runs once the previous one releases.
     */
    public function middleware(): array
    {
        return [
            (new WithoutOverlapping($this->generation->target_type . ':' . $this->generation->target_id))
                ->expireAfter($this->timeout + 30)
                ->releaseAfter(30),
        ];
    }

    public function handle(DocumentationDraftService $service): void
    {
        $result = $service->generate($this->generation);

        $this->generation->update([
            'status' => 'completed',
            'result' => $result->markdown,
            'meta'   => $result->meta,
        ]);
    }

    public function failed(?Throwable $exception): void
    {
        $this->generation->update([
            'status' => 'failed',
            'error'  => $exception?->getMessage(),
        ]);
    }
}
