<?php

namespace App\Jobs;

use App\Models\Submission;
use App\Services\Cati\SlideCondenser;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Queue\Middleware\WithoutOverlapping;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * Rewrites a submission's sections into slide text, in the background.
 *
 * Queued rather than done during the download so "Baixar deck" stays instant:
 * the deck reads `slide_content` off the record, and a model call in a download
 * request would put a 30-second spinner in front of a file that is otherwise
 * ready in under a second.
 */
class CondenseSubmissionForSlides implements ShouldQueue
{
    use Queueable;

    /** Up to `max_attempts` model calls in one run — comfortably under retry_after (900s). */
    public int $timeout = 600;

    /** Each WithoutOverlapping block is released back to the queue and costs an attempt. */
    public int $tries = 25;

    public int $maxExceptions = 3;

    /** @return array<int, int> */
    public function backoff(): array
    {
        return [10, 30, 60];
    }

    public function __construct(public Submission $submission) {}

    /**
     * One condensation per submission at a time. Two runs in parallel would
     * both read the same sections and race to write `slide_content`, and the
     * loser's correction attempts would be spent for nothing.
     */
    public function middleware(): array
    {
        return [
            (new WithoutOverlapping('cati-condense-' . $this->submission->getKey()))
                ->expireAfter($this->timeout + 30)
                ->releaseAfter(30),
        ];
    }

    public function handle(SlideCondenser $condenser): void
    {
        $result = $condenser->handle($this->submission);

        // Sections that never fit are left with the full text, which is verbose
        // but true — worth a log line, since a section that always fails is a
        // prompt problem, not a user problem.
        if ($result['failed'] !== []) {
            Log::info('CATI: sections left uncondensed', [
                'submission_id' => $this->submission->getKey(),
                'sections'      => array_keys($result['failed']),
            ]);
        }
    }

    public function failed(?Throwable $exception): void
    {
        Log::error('CATI: slide condensation failed', [
            'submission_id' => $this->submission->getKey(),
            'exception'     => $exception,
        ]);
    }
}
