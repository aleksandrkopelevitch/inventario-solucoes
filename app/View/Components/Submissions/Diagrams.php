<?php

namespace App\View\Components\Submissions;

use App\Enums\SubmissionDiagramKind;
use App\Models\Submission;
use App\Models\SubmissionDiagram;
use App\View\Components\Concerns\Renderable;
use Illuminate\Contracts\View\View;
use Illuminate\View\Component;

/**
 * The four drawings the committee's checklist asks for, as four slots.
 *
 * Two are drawn on the F3 canvas and two are uploaded — see
 * `App\Enums\SubmissionDiagramKind` for why that split is not laziness. The
 * card only ever shows state and a way in; the drawing itself happens on the
 * canvas's own page, because a pan/zoom surface does not fit in a tab.
 */
class Diagrams extends Component
{
    use Renderable;

    public const DOM_ID = 'submission-diagrams-slot';

    public function __construct(public Submission $submission) {}

    public static function slot(Submission $submission): array
    {
        return (new static($submission))->toSlot(self::DOM_ID);
    }

    public function render(): View
    {
        // Rows are created on demand rather than at submission creation: four
        // empty rows on every record would be four nobody asked for.
        $this->submission->ensureDiagrams();

        $byKind = $this->submission->diagrams()->with('media')->get()->keyBy(fn (SubmissionDiagram $d) => $d->kind->value);

        return view('components.submissions.diagrams', [
            'domId'      => self::DOM_ID,
            'submission' => $this->submission,
            'canEdit'    => auth()->user()?->can('update', $this->submission) ?? false,
            'rows'       => collect(SubmissionDiagramKind::cases())->map(function (SubmissionDiagramKind $kind) use ($byKind) {
                $diagram = $byKind->get($kind->value);

                return [
                    'kind'    => $kind,
                    'diagram' => $diagram,
                    'filled'  => (bool) $diagram?->isFilled(),
                    'picture' => $diagram?->picture(),
                ];
            })->all(),
        ]);
    }
}
