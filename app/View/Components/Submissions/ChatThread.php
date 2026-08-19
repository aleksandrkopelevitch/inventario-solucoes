<?php

namespace App\View\Components\Submissions;

use App\Models\SubmissionChat;
use App\View\Components\Concerns\Renderable;
use Illuminate\Contracts\View\View;
use Illuminate\View\Component;

/**
 * The interview thread: messages, the drafts a reply proposed, and the
 * "gerando…" marker whose presence is what drives cati-chat.js's polling.
 */
class ChatThread extends Component
{
    use Renderable;

    public const DOM_ID = 'submission-chat-thread-slot';

    public function __construct(public SubmissionChat $chat) {}

    public static function slot(SubmissionChat $chat): array
    {
        return (new static($chat))->toSlot(self::DOM_ID);
    }

    public function render(): View
    {
        // Often built from a freshly fetched chat with no relations loaded —
        // strict mode only arms on multi-row hydration (see AGENTS.md).
        $this->chat->loadMissing('submission');
        $messages = $this->chat->messages()->get();

        return view('components.submissions.chat-thread', [
            'domId'    => self::DOM_ID,
            'chat'     => $this->chat,
            'messages' => $messages,
            // Derived from the collection already fetched — same stall bound,
            // without isAwaitingReply()'s extra query.
            'awaiting'  => $this->chat->awaitsReplyFor($messages->last()),
            'statusUrl' => route('submissions.chat.status', [$this->chat->submission, $this->chat]),
        ]);
    }
}
