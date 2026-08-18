<?php

namespace App\View\Components\Submissions;

use App\Enums\SubmissionStatus;
use App\Models\Person;
use App\Models\Solution;
use App\Models\Submission;
use App\View\Components\Concerns\Renderable;
use Illuminate\Contracts\View\View;
use Illuminate\View\Component;

/** Name, status, solution, requester, committee date and ticket — all edited in place. */
class DetailHeader extends Component
{
    use Renderable;

    public const DOM_ID = 'submission-detail-header-slot';

    public function __construct(public Submission $submission) {}

    public static function slot(Submission $submission): array
    {
        return (new static($submission))->toSlot(self::DOM_ID);
    }

    public function render(): View
    {
        $this->submission->loadMissing(['solution', 'requester']);

        return view('components.submissions.detail-header', [
            'domId'      => self::DOM_ID,
            'submission' => $this->submission,
            'canEdit'    => auth()->user()?->can('update', $this->submission) ?? false,
            'statuses'   => SubmissionStatus::options(),
            'solutions'  => Solution::query()->orderBy('name')->get(['id', 'name'])
                ->map(fn (Solution $s) => ['value' => (string) $s->id, 'label' => $s->name])->all(),
            'people' => Person::query()->orderBy('name')->get(['id', 'name'])
                ->map(fn (Person $p) => ['value' => (string) $p->id, 'label' => $p->name])->all(),
        ]);
    }
}
