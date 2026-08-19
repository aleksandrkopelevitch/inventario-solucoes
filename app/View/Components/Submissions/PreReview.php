<?php

namespace App\View\Components\Submissions;

use App\Models\Submission;
use App\View\Components\Concerns\Renderable;
use Illuminate\Contracts\View\View;
use Illuminate\View\Component;

/**
 * What a sceptical committee member would say, read before the meeting.
 *
 * Its own slot because the findings arrive from a job: the presence of the
 * "running" marker inside it is what drives the page's polling, same contract
 * as the interview thread.
 */
class PreReview extends Component
{
    use Renderable;

    public const DOM_ID = 'submission-pre-review-slot';

    public function __construct(public Submission $submission) {}

    public static function slot(Submission $submission): array
    {
        return (new static($submission))->toSlot(self::DOM_ID);
    }

    public function render(): View
    {
        return view('components.submissions.pre-review', [
            'domId'      => self::DOM_ID,
            'submission' => $this->submission,
            'canEdit'    => auth()->user()?->can('update', $this->submission) ?? false,
            'running'    => $this->submission->isPreReviewRunning(),
            'findings'   => $this->submission->preReviewFindings(),
            'statusUrl'  => route('submissions.pre-review.status', $this->submission),
        ]);
    }
}
