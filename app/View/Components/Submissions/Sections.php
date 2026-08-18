<?php

namespace App\View\Components\Submissions;

use App\Enums\SubmissionSectionKey;
use App\Models\Submission;
use App\View\Components\Concerns\Renderable;
use Illuminate\Contracts\View\View;
use Illuminate\View\Component;

/** The eleven section cards — read as prose, edited in place. */
class Sections extends Component
{
    use Renderable;

    public const DOM_ID = 'submission-sections-slot';

    public function __construct(public Submission $submission) {}

    public static function slot(Submission $submission): array
    {
        return (new static($submission))->toSlot(self::DOM_ID);
    }

    public function render(): View
    {
        $this->submission->loadMissing('sections');

        $rows = $this->submission->sections->keyBy(fn ($section) => $section->key->value);

        return view('components.submissions.sections', [
            'domId'      => self::DOM_ID,
            'submission' => $this->submission,
            'canEdit'    => auth()->user()?->can('update', $this->submission) ?? false,
            'sections'   => collect(SubmissionSectionKey::cases())
                ->map(fn (SubmissionSectionKey $key) => [
                    'key'     => $key,
                    'section' => $rows->get($key->value),
                ])
                ->filter(fn (array $row) => $row['section'] !== null)
                ->values(),
        ]);
    }
}
