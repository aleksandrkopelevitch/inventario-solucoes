<?php

namespace App\View\Components\Submissions;

use App\Models\Submission;
use App\Support\Cati\ConformanceChecks;
use App\Support\Cati\DeviationRules;
use App\Support\Cati\SubmissionRequirements;
use App\View\Components\Concerns\Renderable;
use Illuminate\Contracts\View\View;
use Illuminate\View\Component;

/**
 * What the committee will push back on: the structural items, the corporate
 * standards, and the questions the record itself raises. Deterministic — no
 * model call, so it is correct and free on every render.
 *
 * The catalog's facts and the per-section progress used to live here too;
 * they moved to `Submissions\Progress`, which sits next to the interview.
 * They answer "what is left to write"; this card answers "what will be
 * argued about", and only the second belongs on the review surface.
 */
class Checklist extends Component
{
    use Renderable;

    public const DOM_ID = 'submission-checklist-slot';

    public function __construct(public Submission $submission) {}

    public static function slot(Submission $submission): array
    {
        return (new static($submission))->toSlot(self::DOM_ID);
    }

    public function render(): View
    {
        return view('components.submissions.checklist', [
            'domId'       => self::DOM_ID,
            'structural'  => SubmissionRequirements::for($this->submission)['structural'],
            'conformance' => ConformanceChecks::for($this->submission),
            'deviations'  => collect(DeviationRules::for($this->submission))
                ->sortBy(fn (array $rule) => ['high' => 0, 'medium' => 1, 'low' => 2][$rule['severity']] ?? 3)
                ->values(),
        ]);
    }
}
