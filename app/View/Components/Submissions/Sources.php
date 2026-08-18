<?php

namespace App\View\Components\Submissions;

use App\Models\Submission;
use App\View\Components\Concerns\Renderable;
use Illuminate\Contracts\View\View;
use Illuminate\View\Component;

/** The gathered material, with what could be read out of each file. */
class Sources extends Component
{
    use Renderable;

    public const DOM_ID = 'submission-sources-slot';

    public function __construct(public Submission $submission) {}

    public static function slot(Submission $submission): array
    {
        return (new static($submission))->toSlot(self::DOM_ID);
    }

    public function render(): View
    {
        $this->submission->loadMissing('sources');

        return view('components.submissions.sources', [
            'domId'      => self::DOM_ID,
            'submission' => $this->submission,
            'canEdit'    => auth()->user()?->can('update', $this->submission) ?? false,
            'sources'    => $this->submission->sources,
        ]);
    }
}
