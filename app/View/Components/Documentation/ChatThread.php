<?php

namespace App\View\Components\Documentation;

use App\Models\DocumentationChat;
use App\View\Components\Concerns\Renderable;
use Illuminate\Contracts\View\View;
use Illuminate\View\Component;

/**
 * Thread for a Documentation Assistant chat, renderable as an updatable slot
 * (`documentation-chat-thread-slot`): messages, the "Ver alterações" diff
 * action on an assistant message that carries a draft, and the "gerando…"
 * marker that triggers polling (docs-chat.js).
 */
class ChatThread extends Component
{
    use Renderable;

    public const DOM_ID = 'documentation-chat-thread-slot';

    public function __construct(public DocumentationChat $chat) {}

    public static function slot(DocumentationChat $chat): array
    {
        return (new static($chat))->toSlot(self::DOM_ID);
    }

    public function render(): View
    {
        // Explicit eager load: this component is often built from a freshly
        // fetched/deserialized chat with no relations loaded (strict mode only
        // arms on multi-row hydration — see AGENTS.md).
        $this->chat->loadMissing('solution');
        $messages = $this->chat->messages()->get();

        return view('components.documentation.chat-thread', [
            'domId'    => self::DOM_ID,
            'chat'     => $this->chat,
            'messages' => $messages,
            // Derives from the collection already fetched — avoids the extra
            // query from isAwaitingReply() while applying the same stall bound.
            'awaiting'  => $this->chat->awaitsReplyFor($messages->last()),
            'statusUrl' => route('solutions.docs.chat.status', [$this->chat->solution, $this->chat]),
        ]);
    }
}
