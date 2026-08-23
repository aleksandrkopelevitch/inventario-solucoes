<?php

namespace App\View\Components\Submissions;

use App\Models\Submission;
use App\Support\Cati\SubmissionStages;
use App\View\Components\Concerns\Renderable;
use Illuminate\Contracts\View\View;
use Illuminate\View\Component;

/**
 * Where this submission is: Material → Entrevista → Revisão → Comitê.
 *
 * Read-only by design (see App\Support\Cati\SubmissionStages) — clicking a
 * stage navigates, it never marks anything done.
 */
class StageStrip extends Component
{
    use Renderable;

    public const DOM_ID = 'submission-stage-strip-slot';

    public function __construct(public Submission $submission) {}

    public static function slot(Submission $submission): array
    {
        return (new static($submission))->toSlot(self::DOM_ID);
    }

    public function render(): View
    {
        return view('components.submissions.stage-strip', [
            'domId'  => self::DOM_ID,
            'stages' => SubmissionStages::for($this->submission),
        ]);
    }
}
