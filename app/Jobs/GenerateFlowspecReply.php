<?php

namespace App\Jobs;

use App\Models\FlowspecMessage;
use App\Services\Flowspec\FlowspecGenerationService;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Queue\Middleware\WithoutOverlapping;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * Generates the assistant's reply to an Especialista em Integrações (F8)
 * chat message. The UI doesn't wait for the job: the thread shows
 * "generating…" while the last chat message is from the user, and a
 * lightweight poll (flowspec-chat.js) updates the slot once this reply is
 * persisted — including the failure reply created in failed(), so the chat
 * never stays pending forever.
 */
class GenerateFlowspecReply implements ShouldQueue
{
    use Queueable;

    /** Up to 3 generation/correction attempts against the API — well below the retry_after (900s). */
    public int $timeout = 600;

    /**
     * Sized for the worst case of WithoutOverlapping: each "wait" (a job
     * blocked by another one for the same chat) goes back to the queue via
     * release() and consumes one attempt — not a real failure, just queuing.
     * A job ahead can hold the lock for up to $timeout (600s), and the
     * blocked one retries every releaseAfter (30s): 600/30 = 20 possible
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

    public function __construct(public FlowspecMessage $userMessage) {}

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
            (new WithoutOverlapping($this->userMessage->flowspec_chat_id))
                ->expireAfter($this->timeout + 30)
                ->releaseAfter(30),
        ];
    }

    public function handle(FlowspecGenerationService $service): void
    {
        if ($this->isSuperseded()) {
            return;
        }

        $result = $service->generate($this->userMessage);

        $content = match (true) {
            // A literal secret survived every attempt — the service withheld
            // both the document and the raw text, so say so instead of leaking.
            $result->credentialLeak    => 'A geração produziu uma credencial literal no flowSpec e o resultado foi descartado por segurança. Refaça pedindo que valores sensíveis usem referências {{ account.* }} / {{ global.* }} em vez de literais.',
            $result->document === null => $result->text,
            $result->validated         => 'flowSpec gerado e validado — pronto para colar no canvas da Digibee.',
            default                    => 'flowSpec gerado, mas a validação ainda apontou pendências depois de todas as tentativas — revise os erros abaixo antes de colar.',
        };

        $this->userMessage->chat->messages()->create([
            'role'      => 'assistant',
            'content'   => $content,
            'flow_spec' => $result->document,
            'meta'      => [...$result->meta, 'validated' => $result->validated],
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
            Log::error('flowSpec: reply generation failed', [
                'chat_id'    => $this->userMessage->flowspec_chat_id,
                'message_id' => $this->userMessage->id,
                'exception'  => $exception,
            ]);
        }

        $this->userMessage->chat->messages()->create([
            'role'    => 'assistant',
            'content' => 'Não consegui gerar o flowSpec — a chamada ao modelo falhou. Tente novamente em instantes.',
            'meta'    => ['status' => 'failed', 'error_type' => $exception ? $exception::class : null],
        ]);
    }

    /**
     * True when a later message already exists in the chat, so this turn has
     * been superseded and must produce no reply. Covers two orderings that
     * WithoutOverlapping serializes but does NOT de-duplicate: a job the queue
     * resurrected (retry_after) after a hard worker kill, once the stall guard
     * (FlowspecChat::REPLY_STALL_SECONDS) reopened the composer and the user
     * sent again; and a double-submit race that slipped two messages past the
     * non-atomic FlowspecMessageController guard. Guarding both handle() and
     * failed() means the latest message gets exactly one reply, in any order.
     */
    private function isSuperseded(): bool
    {
        $this->userMessage->loadMissing('chat');

        return $this->userMessage->chat->messages()
            ->where('id', '>', $this->userMessage->id)
            ->exists();
    }
}
