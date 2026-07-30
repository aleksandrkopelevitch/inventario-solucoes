<?php

namespace App\View\Components\Flowspec;

use App\Models\FlowspecChat;
use App\Support\GitbookRenderer;
use App\View\Components\Concerns\Renderable;
use Illuminate\Contracts\View\View;
use Illuminate\View\Component;

/**
 * Thread for a flowSpec generator (F8) chat, renderable as an updatable slot
 * (`flowspec-thread-slot`): messages, JSON block with copy, validation badge
 * and the "gerando…" marker that triggers polling (flowspec-chat.js).
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

        // Assistant replies are AI-generated Markdown (FlowspecMessage::content
        // is a plain string, never Editor.js JSON) — rendered here, same as
        // documentation's GitbookRenderer::render() + `.html-content`, so the
        // blade partial only ever echoes pre-built HTML.
        //
        // FlowspecGenerationService can exhaust its correction attempts on a
        // response it never managed to parse into a flowSpec document — when
        // that happens, GenerateFlowspecReply falls back to the model's raw
        // text as `content` (flow_spec stays null). That raw text is often
        // itself a JSON blob, which reads as an unformatted wall of text once
        // pushed through Markdown — so it's shown as code instead, same box
        // treatment as the flow_spec JSON below (pretty-printed if it happens
        // to be valid JSON, verbatim otherwise since it may be truncated).
        $renderer = app(GitbookRenderer::class);
        $rendered = $messages
            ->filter(fn ($message) => $message->role !== 'user')
            ->mapWithKeys(function ($message) use ($renderer) {
                if (! $message->hasRawJsonContent()) {
                    return [$message->id => ['type' => 'markdown', 'content' => $renderer->render($message->content)]];
                }

                $decoded = json_decode(trim($message->content), true);
                $pretty = json_last_error() === JSON_ERROR_NONE
                    ? json_encode($decoded, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES)
                    : $message->content;

                return [$message->id => ['type' => 'raw', 'content' => $pretty]];
            });

        return view('components.flowspec.thread', [
            'domId'    => self::DOM_ID,
            'chat'     => $this->chat,
            'messages' => $messages,
            'rendered' => $rendered,
            // Derives from the collection already fetched — avoids the extra
            // query from isAwaitingReply() while applying the same stall bound.
            'awaiting' => $this->chat->awaitsReplyFor($messages->last()),
        ]);
    }
}
