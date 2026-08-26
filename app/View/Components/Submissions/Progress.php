<?php

namespace App\View\Components\Submissions;

use App\Enums\SubmissionSectionState;
use App\Models\Submission;
use App\Support\Cati\SubmissionRequirements;
use App\Support\Cati\SubmissionStages;
use App\View\Components\Concerns\Renderable;
use Illuminate\Contracts\View\View;
use Illuminate\View\Component;

/**
 * The document taking shape, next to the conversation that is writing it.
 *
 * Two blocks, and the order is the argument: what still needs an answer
 * (eleven sections, each with its state), and — underneath — what the catalog
 * already answered so nobody is asked for it. Split out of `Checklist`, which
 * kept both of these next to the conformance verdicts and the committee's
 * questions; those belong to review, not to the interview, and mixing them
 * made the rail a wall nobody read.
 */
class Progress extends Component
{
    use Renderable;

    public const DOM_ID = 'submission-progress-slot';

    public function __construct(public Submission $submission) {}

    public static function slot(Submission $submission): array
    {
        return (new static($submission))->toSlot(self::DOM_ID);
    }

    public function render(): View
    {
        $requirements = SubmissionRequirements::for($this->submission);

        return view('components.submissions.progress', [
            'domId'    => self::DOM_ID,
            'sections' => $requirements['sections'],
            'facts'    => $requirements['facts'],
            'progress' => SubmissionStages::progress($this->submission),
            // The next section that has text nobody has signed off on — what
            // the "ir à próxima" button jumps to. Written sections only: an
            // empty one is the interview's job, not the reviewer's, and
            // sending someone to confirm a blank card is sending them nowhere.
            'nextUnconfirmed' => collect($requirements['sections'])
                ->first(fn (array $section) => $section['answered']
                    && $section['state'] !== SubmissionSectionState::Confirmed->value),
        ]);
    }
}
