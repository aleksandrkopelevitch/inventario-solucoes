<?php

namespace App\View\Components\Submissions;

use App\Models\Submission;
use App\Support\Cati\DeviationRules;
use App\Support\Cati\SubmissionRequirements;
use App\View\Components\Concerns\Renderable;
use Illuminate\Contracts\View\View;
use Illuminate\View\Component;

/**
 * What the catalog already knows, what is still missing, and what the
 * committee would ask. Deterministic — no model call, so it is correct and
 * free on every render.
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
        $requirements = SubmissionRequirements::for($this->submission);

        return view('components.submissions.checklist', [
            'domId'      => self::DOM_ID,
            'facts'      => $requirements['facts'],
            'structural' => $requirements['structural'],
            'missing'    => SubmissionRequirements::missingMandatory($this->submission),
            'deviations' => collect(DeviationRules::for($this->submission))
                ->sortBy(fn (array $rule) => ['high' => 0, 'medium' => 1, 'low' => 2][$rule['severity']] ?? 3)
                ->values(),
        ]);
    }
}
