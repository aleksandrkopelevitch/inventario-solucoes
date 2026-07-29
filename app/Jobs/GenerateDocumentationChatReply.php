<?php

namespace App\Jobs;

use App\Models\DocumentationChatMessage;
use App\Services\Documentation\DocumentationChatService;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Queue\Middleware\WithoutOverlapping;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * Generates the Documentation Assistant's reply to one chat turn. The UI
 * doesn't wait for the job: the thread shows "gerando…" while the last
 * message is from the user, and a lightweight poll (docs-chat.js) updates the
 * slot once this reply is persisted — including the failure reply created in
 * failed(), so the chat never stays pending forever.
 */
class GenerateDocumentationChatReply implements ShouldQueue
{
    use Queueable;

    /** Single model call (no correction loop, unlike flowSpec) — comfortably below the retry_after (900s). */
    public int $timeout = 240;

    /**
     * Sized for the worst case of WithoutOverlapping: each "wait" (a job
     * blocked by another one for the same chat) goes back to the queue via
     * release() and consumes one attempt — not a real failure, just queuing.
     * A job ahead can hold the lock for up to $timeout (240s), and the
     * blocked one retries every releaseAfter (30s): 240/30 = 8 possible
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

    public function __construct(public DocumentationChatMessage $userMessage) {}

    /**
     * Serializes generation per chat: two user messages in quick succession
     * (double-click before the button disables, two tabs) can't run in
     * parallel — each would read the same history and create a concurrent
     * assistant reply, breaking the "one pending turn at a time" model that
     * isAwaitingReply() assumes. The blocked job goes back to the queue (it's
     * not discarded) and runs as soon as the previous one releases, already
     * seeing the previous reply in the history.
     */
    public function middleware(): array
    {
        return [
            (new WithoutOverlapping($this->userMessage->documentation_chat_id))
                ->expireAfter($this->timeout + 30)
                ->releaseAfter(30),
        ];
    }

    public function handle(DocumentationChatService $service): void
    {
        if ($this->isSuperseded()) {
            return;
        }

        $reply = $service->generate($this->userMessage);

        $this->userMessage->chat->messages()->create([
            'role'    => 'assistant',
            'content' => $reply->content,
            'draft'   => $reply->draft,
            'meta'    => $reply->meta,
        ]);
    }

    public function failed(?Throwable $exception): void
    {
        if ($this->isSuperseded()) {
            return;
        }

        // Log the full exception server-side for debugging, but persist only
        // its type: a provider error message can embed the request URL, headers
        // or key fragments, and `meta` is an audit trail that could be exported.
        if ($exception !== null) {
            Log::error('Documentation Assistant: reply generation failed', [
                'chat_id'    => $this->userMessage->documentation_chat_id,
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
     * True when a later message already exists in the chat, so this turn has
     * been superseded and must produce no reply. Covers two orderings that
     * WithoutOverlapping serializes but does NOT de-duplicate: a job the queue
     * resurrected (retry_after) after a hard worker kill, once the stall guard
     * (DocumentationChat::REPLY_STALL_SECONDS) reopened the composer and the
     * user sent again; and a double-submit race that slipped two messages past
     * the non-atomic controller guard. Guarding both handle() and failed()
     * means the latest message gets exactly one reply, in any order.
     */
    private function isSuperseded(): bool
    {
        $this->userMessage->loadMissing('chat');

        return $this->userMessage->chat->messages()
            ->where('id', '>', $this->userMessage->id)
            ->exists();
    }
}
