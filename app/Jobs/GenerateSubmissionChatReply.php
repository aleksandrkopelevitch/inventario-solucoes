<?php

namespace App\Jobs;

use App\Models\SubmissionMessage;
use App\Services\Cati\SubmissionChatService;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Queue\Middleware\WithoutOverlapping;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * Generates the CATI interview's reply to one turn. The UI doesn't wait: the
 * thread shows "gerando…" while the last message is the user's, and a
 * lightweight poll swaps the slot once this reply is persisted — including
 * the failure reply from failed(), so the chat never stays pending forever.
 *
 * Deliberately identical in shape to GenerateDocumentationChatReply. Every
 * number below was arrived at there; diverging without a reason just means
 * finding the same bugs twice.
 */
class GenerateSubmissionChatReply implements ShouldQueue
{
    use Queueable;

    /** Single model call (no correction loop) — comfortably below retry_after (900s on the database queue). */
    public int $timeout = 240;

    /**
     * Sized for the worst case of WithoutOverlapping: each "wait" (a job
     * blocked by another one for the same chat) is released back to the queue
     * and consumes one attempt — that's queuing, not failure. A job ahead can
     * hold the lock for up to $timeout (240s) and the blocked one retries
     * every releaseAfter (30s): 240/30 = 8 possible releases, plus margin.
     * REAL failures are bounded by $maxExceptions, not by this.
     */
    public int $tries = 25;

    /** Without this, the high $tries above would mean 25 API calls during an outage. */
    public int $maxExceptions = 3;

    /** @return array<int, int> exponential backoff between real exceptions */
    public function backoff(): array
    {
        return [10, 30, 60];
    }

    public function __construct(public SubmissionMessage $userMessage) {}

    /**
     * Serializes generation per chat. An interview is sequential by nature —
     * each question depends on the previous answer — so two turns running at
     * once would each read the same history and produce a reply to a
     * conversation that no longer exists.
     */
    public function middleware(): array
    {
        return [
            (new WithoutOverlapping($this->userMessage->submission_chat_id))
                ->expireAfter($this->timeout + 30)
                ->releaseAfter(30),
        ];
    }

    public function handle(SubmissionChatService $service): void
    {
        if ($this->isSuperseded()) {
            return;
        }

        $reply = $service->generate($this->userMessage);

        $this->userMessage->chat->messages()->create([
            'role'    => 'assistant',
            'content' => $reply->content,
            'drafts'  => $reply->drafts ?: null,
            'meta'    => $reply->meta,
        ]);
    }

    public function failed(?Throwable $exception): void
    {
        if ($this->isSuperseded()) {
            return;
        }

        // The full exception is logged server-side; only its type is persisted.
        // A provider error message can embed the request URL, headers or key
        // fragments, and `meta` is an audit trail that could be exported.
        if ($exception !== null) {
            Log::error('CATI: interview reply generation failed', [
                'chat_id'    => $this->userMessage->submission_chat_id,
                'message_id' => $this->userMessage->id,
                'exception'  => $exception,
            ]);
        }

        $this->userMessage->chat->messages()->create([
            'role'    => 'assistant',
            'content' => 'Não consegui gerar uma resposta — a chamada ao modelo falhou. Tente novamente em instantes.',
            'meta'    => ['status' => 'failed', 'error_type' => $exception ? $exception::class : null],
        ]);
    }

    /**
     * True when a later message already exists, so this turn was superseded and
     * must produce no reply. Covers two orderings WithoutOverlapping serializes
     * but does NOT de-duplicate: a job the queue resurrected (retry_after)
     * after a hard worker kill, once the stall guard
     * (SubmissionChat::REPLY_STALL_SECONDS) reopened the composer and the user
     * sent again; and a double-submit race past the non-atomic controller
     * guard. Guarding handle() AND failed() means the latest message gets
     * exactly one reply, in any order.
     */
    private function isSuperseded(): bool
    {
        $this->userMessage->loadMissing('chat');

        return $this->userMessage->chat->messages()
            ->where('id', '>', $this->userMessage->id)
            ->exists();
    }
}
