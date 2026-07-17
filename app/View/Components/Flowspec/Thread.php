<?php

namespace App\View\Components\Flowspec;

use App\Models\FlowspecChat;
use App\Models\Integration;
use App\View\Components\Concerns\Renderable;
use Illuminate\Contracts\View\View;
use Illuminate\View\Component;

/**
 * Thread for a flowSpec generator (F8) chat, renderable as an updatable slot
 * (`flowspec-thread-slot`): messages, JSON block with copy, validation badge,
 * attach/promote forms and the "gerando…" marker that triggers polling
 * (flowspec-chat.js).
 */
class Thread extends Component
{
    use Renderable;

    public const DOM_ID = 'flowspec-thread-slot';

    public function __construct(public FlowspecChat $chat) {}

    public static function slot(FlowspecChat $chat): array
    {
        return (new static($chat))->toSlot(self::DOM_ID);
    }

    public function render(): View
    {
        $messages = $this->chat->messages()->get();

        return view('components.flowspec.thread', [
            'domId'        => self::DOM_ID,
            'chat'         => $this->chat,
            'messages'     => $messages,
            'integrations' => Integration::query()->orderBy('name')->get(['id', 'name']),
            // Derives from the collection already fetched — avoids the extra
            // query from isAwaitingReply() (same rule: last message is from the user).
            'awaiting' => $messages->last()?->role === 'user',
        ]);
    }
}
