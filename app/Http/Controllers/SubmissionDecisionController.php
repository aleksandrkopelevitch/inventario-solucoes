<?php

namespace App\Http\Controllers;

use App\Actions\Cati\PromoteApprovedSubmission;
use App\Enums\SubmissionStatus;
use App\Http\Requests\RecordSubmissionDecisionRequest;
use App\Models\Submission;
use App\View\Components\Submissions\Checklist;
use App\View\Components\Submissions\DetailHeader;
use Illuminate\Http\JsonResponse;

/**
 * The committee's deliberation, recorded on the submission.
 *
 * An approval also PROMOTES: the sections that describe how the thing works go
 * into the Solution's documentation. Without that step this module is a slide
 * factory — what the committee approved would live in a `.pptx` and the
 * catalog would keep drifting until the next submission reconstructs the
 * AS-IS by hand.
 */
class SubmissionDecisionController extends Controller
{
    public function store(RecordSubmissionDecisionRequest $request, Submission $submission, PromoteApprovedSubmission $promote): JsonResponse
    {
        $status = SubmissionStatus::from($request->validated('status'));

        $submission->update([
            'status'     => $status,
            'decision'   => $request->validated('decision'),
            'conditions' => array_map(
                fn (string $text) => ['text' => $text, 'done' => false],
                $request->validated('conditions', []),
            ),
            'decided_at'    => now(),
            'decided_by_id' => $request->user()->id,
        ]);

        $page = null;

        if ($status === SubmissionStatus::Approved || $status === SubmissionStatus::ApprovedWithConditions) {
            $page = $promote->handle($submission->fresh(['sections', 'solution']));
        }

        return response()->json([
            'type'    => 'success',
            'message' => $page !== null
                ? 'Deliberação registrada e publicada na documentação da solução.'
                : 'Deliberação registrada.',
            'updatableSlots' => [
                DetailHeader::slot($submission->fresh(['solution', 'requester'])),
                Checklist::slot($submission->fresh(['sections', 'sources', 'solution'])),
            ],
        ]);
    }
}
