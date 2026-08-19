<?php

namespace App\View\Components\Submissions;

use App\Models\Submission;
use App\View\Components\Concerns\Renderable;
use Illuminate\Contracts\View\View;
use Illuminate\View\Component;

/**
 * What the committee decided, and the conditions it attached.
 *
 * Its own slot so a condition can be ticked off without reloading the page —
 * which is the whole point of storing them as a list: a ressalva nobody can
 * close is a ressalva nobody follows up on.
 */
class Deliberation extends Component
{
    use Renderable;

    public const DOM_ID = 'submission-deliberation-slot';

    public function __construct(public Submission $submission) {}

    public static function slot(Submission $submission): array
    {
        return (new static($submission))->toSlot(self::DOM_ID);
    }

    public function render(): View
    {
        $this->submission->loadMissing('decidedBy');

        return view('components.submissions.deliberation', [
            'domId'      => self::DOM_ID,
            'submission' => $this->submission,
            'canEdit'    => auth()->user()?->can('update', $this->submission) ?? false,
            'conditions' => $this->submission->conditions ?? [],
        ]);
    }
}
