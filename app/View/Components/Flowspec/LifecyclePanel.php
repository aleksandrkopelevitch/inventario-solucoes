<?php

namespace App\View\Components\Flowspec;

use App\Enums\DigibeeTriggerKind;
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

    /**
     * Words kept from the conversation's title when suggesting a pipeline
     * name. Four is what the tenant's own names use — three to five segments.
     */
    private const NAME_WORDS = 4;

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

    /**
     * A starting point for the pipeline's name, from the conversation's title.
     *
     * Slugged because that is what the platform addresses a pipeline by and
     * what lands in the endpoint URL — and cut to the first few words, because
     * a chat title is a SENTENCE. The first real run in production suggested
     * `crie-uma-integracao-digibee-da-secao-consultar-status-entrega-pedido-vamos-abri`,
     * 79 characters, which validates fine and which nobody wants as a pipeline
     * name: the tenant's own are `zfl-bloq-desbloq-cliente`, `get-token-cws`,
     * `api-transfere-anexos-freshworks`.
     *
     * Whole segments only, never a character cut: a slug may not end in a
     * hyphen (`App\Rules\AsciiSlug`), so trimming mid-word would suggest a
     * name the form then refuses. And it stays a SUGGESTION — the field is
     * free text, because only a person knows what the pipeline should be
     * called, and on this platform the name is permanent.
     */
    private static function suggestName(?string $title): string
    {
        $slug = Str::slug((string) $title);

        if ($slug === '') {
            return 'pipeline';
        }

        return implode('-', array_slice(explode('-', $slug), 0, self::NAME_WORDS));
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
            'suggestedName' => self::suggestName($this->chatTitle ?? $this->message->chat?->title),
            'environments'  => (array) config('services.digibee.design.deployable_environments'),
            // The five the platform has. The form offers them because the
            // lifecycle used to write none, which is what left every pipeline
            // it created undeployable.
            'triggerKinds'  => DigibeeTriggerKind::cases(),
        ]);
    }
}
