<?php

namespace App\View\Components\Flowspec;

use App\Models\FlowspecChat;
use App\View\Components\Concerns\Renderable;
use Illuminate\Contracts\View\View;
use Illuminate\View\Component;

/**
 * The conversation's context, as an updatable slot (`flowspec-context-slot`):
 * the attached documents/files/pastes, each removable.
 *
 * `$chat` is nullable: the new-chat screen has no conversation yet and the panel
 * still renders — empty — so the composer looks and behaves the same before and
 * after the first message.
 */
class ContextPanel extends Component
{
    use Renderable;

    public const DOM_ID = 'flowspec-context-slot';

    public function __construct(public ?FlowspecChat $chat = null) {}

    public static function slot(?FlowspecChat $chat = null): array
    {
        return (new static($chat))->toSlot(self::DOM_ID);
    }

    public function render(): View
    {
        $attachments = $this->chat === null
            ? collect()
            : $this->chat->attachments()->with('media')->get();

        return view('components.flowspec.context-panel', [
            'domId'       => self::DOM_ID,
            'chat'        => $this->chat,
            'attachments' => $attachments,
        ]);
    }
}
