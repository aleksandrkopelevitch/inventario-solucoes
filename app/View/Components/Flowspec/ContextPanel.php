<?php

namespace App\View\Components\Flowspec;

use App\Models\FlowspecChat;
use App\Services\Flowspec\FlowspecContextBudget;
use App\View\Components\Concerns\Renderable;
use Illuminate\Contracts\View\View;
use Illuminate\View\Component;

/**
 * The conversation's context, as an updatable slot (`flowspec-context-slot`):
 * the attached documents/files/pastes, each removable, plus the meter showing
 * what they cost against the limit.
 *
 * One component, not two, even though it renders two visually distinct things:
 * every mutation changes both at once (attaching a document moves the bar,
 * removing one moves it back), and splitting them into separate slots would
 * mean every attach endpoint has to remember to return both — the exact bug
 * class the "Multiple *different* slots from one mutation" note in AGENTS.md
 * warns about, but self-inflicted.
 *
 * `$chat` is nullable: the new-chat screen has no conversation yet, and the
 * panel still renders — empty list, meter showing only the fixed prompt cost —
 * so the composer looks and behaves the same before and after the first
 * message.
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
            'usage'       => app(FlowspecContextBudget::class)->for($this->chat),
        ]);
    }
}
