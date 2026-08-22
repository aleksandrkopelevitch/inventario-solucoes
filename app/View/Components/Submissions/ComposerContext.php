<?php

namespace App\View\Components\Submissions;

use App\Models\Submission;
use App\View\Components\Concerns\Renderable;
use Illuminate\Contracts\View\View;
use Illuminate\View\Component;

/**
 * The material chips sitting above the interview's textarea.
 *
 * Its own slot, separate from the composer form that holds it, for one
 * reason: attaching a file mid-sentence must not swap the textarea out from
 * under what is being typed. Same split as the flowSpec composer, where
 * `x-flowspec.context-panel` is the slot and the form around it is static.
 *
 * Shows the same rows as `Submissions\Sources` at a different altitude —
 * here, what the next message will carry; there, the material with its
 * extraction state and its credential warnings. Both are always returned
 * together after a mutation.
 */
class ComposerContext extends Component
{
    use Renderable;

    public const DOM_ID = 'submission-composer-context-slot';

    public function __construct(public Submission $submission) {}

    public static function slot(Submission $submission): array
    {
        return (new static($submission))->toSlot(self::DOM_ID);
    }

    public function render(): View
    {
        // See the note in Submissions\Sources: the chip's size hint reads
        // $source->media for a file with no extracted text.
        $this->submission->loadMissing('sources.media');

        return view('components.submissions.composer-context', [
            'domId'      => self::DOM_ID,
            'submission' => $this->submission,
            'sources'    => $this->submission->sources,
            'canEdit'    => auth()->user()?->can('update', $this->submission) ?? false,
        ]);
    }
}
