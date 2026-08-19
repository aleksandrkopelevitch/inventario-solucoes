<?php

namespace App\Jobs;

use App\Models\Submission;
use App\Services\Cati\PreReviewService;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Queue\Middleware\WithoutOverlapping;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * Reads a submission the way the committee will, before the committee does.
 */
class PreReviewSubmission implements ShouldQueue
{
    use Queueable;

    public int $timeout = 300;

    /** Each WithoutOverlapping block is released back to the queue and costs an attempt. */
    public int $tries = 25;

    public int $maxExceptions = 3;

    /** @return array<int, int> */
    public function backoff(): array
    {
        return [10, 30, 60];
    }

    public function __construct(public Submission $submission) {}

    public function middleware(): array
    {
        return [
            (new WithoutOverlapping('cati-prereview-' . $this->submission->getKey()))
                ->expireAfter($this->timeout + 30)
                ->releaseAfter(30),
        ];
    }

    public function handle(PreReviewService $service): void
    {
        $result = $service->handle($this->submission);

        $this->submission->update([
            'pre_review'      => $result,
            'pre_reviewed_at' => now(),
        ]);
    }

    public function failed(?Throwable $exception): void
    {
        Log::error('CATI: pre-review failed', [
            'submission_id' => $this->submission->getKey(),
            'exception'     => $exception,
        ]);

        // Stamped even on failure, so the page stops saying "running" and the
        // button becomes clickable again.
        $this->submission->update([
            'pre_review'      => ['findings' => [], 'meta' => ['status' => 'failed']],
            'pre_reviewed_at' => now(),
        ]);
    }
}
