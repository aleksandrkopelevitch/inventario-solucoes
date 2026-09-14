<?php

namespace App\View\Components\Flowspec;

use App\Models\FlowspecMessage;
use App\Models\PipelineRun;
use App\View\Components\Concerns\Renderable;
use Illuminate\Contracts\View\View;
use Illuminate\Support\Str;
use Illuminate\View\Component;

/**
 * The lifecycle panel on the message that produced a flowSpec: the button that
 * starts a run, and the rounds as they land.
 *
 * Its slot id carries the MESSAGE id, because a conversation can generate more
 * than once and each generation gets its own panel — a single id per chat would
 * make a poll swap the wrong message's panel the moment somebody regenerated.
 */
class LifecyclePanel extends Component
{
    use Renderable;

    public function __construct(public FlowspecMessage $message, public ?string $chatTitle = null) {}

    /**
     * Named `slotId` and not `domId` for a reason worth keeping: a PUBLIC
     * METHOD on a class component is handed to its view as a closure under its
     * own name, and it SHADOWS a variable of that name passed by `render()`.
     * With both called `domId`, `{{ $domId }}` echoed the closure and the view
     * died with "htmlspecialchars(): Argument #1 must be of type string,
     * Closure given" — pointing at a compiled file, on a line that reads
     * perfectly.
     */
    public static function slotId(FlowspecMessage $message): string
    {
        return 'flowspec-lifecycle-' . $message->id;
    }

    public static function slot(FlowspecMessage $message): array
    {
        return (new static($message))->toSlot(self::slotId($message));
    }

    public function render(): View
    {
        $run = PipelineRun::query()
            ->where('flowspec_message_id', $this->message->id)
            ->latest('id')
            ->first();

        $this->message->loadMissing('chat');

        return view('components.flowspec.lifecycle-panel', [
            'domId' => self::slotId($this->message),
            'chat'  => $this->message->chat,
            'run'   => $run,
            // The default name a person will almost always accept, derived the
            // same way the test matrix already derives its suite name. It has
            // to be a slug: it is what the platform addresses the pipeline by,
            // and it lands in the endpoint URL.
            'suggestedName' => Str::slug($this->chatTitle ?? $this->message->chat?->title ?? 'pipeline'),
            'environments'  => (array) config('services.digibee.design.deployable_environments'),
        ]);
    }
}
