<?php

namespace App\Http\Controllers;

use App\Enums\SubmissionSectionState;
use App\Http\Requests\UpdateSubmissionSectionRequest;
use App\Models\Submission;
use App\Models\SubmissionSection;
use App\View\Components\Submissions\Checklist;
use App\View\Components\Submissions\Sections;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * A submission's sections, edited in place.
 *
 * Two distinct gestures on purpose: editing the text leaves the section
 * `drafted` (nobody has signed it yet), and confirming is a separate,
 * explicit act. That distinction is what the ticket's final checklist and the
 * document's "rascunho da IA" marker both read — collapsing the two would put
 * unreviewed text in front of the committee looking reviewed.
 */
class SubmissionSectionController extends Controller
{
    public function update(UpdateSubmissionSectionRequest $request, Submission $submission, SubmissionSection $section): JsonResponse
    {
        $content = $request->validated('content');

        $section->update([
            'content' => $content,
            // Typed by a human, so it is confirmed: the person editing IS the
            // author. Emptying it takes the section back to blank.
            'state'         => blank($content) ? SubmissionSectionState::Empty : SubmissionSectionState::Confirmed,
            'updated_by_id' => $request->user()->id,
        ]);

        return $this->saved($submission, 'Seção salva.');
    }

    /** Signs off on text the assistant drafted, without changing a word of it. */
    public function confirm(Request $request, Submission $submission, SubmissionSection $section): JsonResponse
    {
        $this->authorize('update', $submission);

        if (blank($section->content)) {
            return response()->json([
                'type'    => 'warning',
                'message' => 'Não dá para confirmar uma seção vazia.',
            ], 422);
        }

        $section->update([
            'state'         => SubmissionSectionState::Confirmed,
            'updated_by_id' => $request->user()->id,
        ]);

        return $this->saved($submission, 'Seção confirmada.');
    }

    private function saved(Submission $submission, string $message): JsonResponse
    {
        // Reload before rendering: the card components read the relation, and
        // without this they answer with the pre-mutation copy.
        $submission->load(['sections', 'sources', 'solution']);

        return response()->json([
            'type'           => 'success',
            'message'        => $message,
            'updatableSlots' => [Sections::slot($submission), Checklist::slot($submission)],
        ]);
    }
}
