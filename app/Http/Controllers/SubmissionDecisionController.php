<?php

namespace App\Http\Controllers;

use App\Actions\Cati\PromoteApprovedSubmission;
use App\Enums\SubmissionStatus;
use App\Http\Requests\RecordSubmissionDecisionRequest;
use App\Jobs\PreReviewSubmission;
use App\Models\Submission;
use App\View\Components\Submissions\Checklist;
use App\View\Components\Submissions\DetailHeader;
use App\View\Components\Submissions\PreReview;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

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
    /** Queues the adversarial read of the submission. */
    public function preReview(Request $request, Submission $submission): JsonResponse
    {
        $this->authorize('update', $submission);

        if ($submission->isPreReviewRunning()) {
            return response()->json([
                'type'    => 'warning',
                'message' => 'A revisão anterior ainda está rodando.',
            ], 422);
        }

        $submission->update(['pre_review_requested_at' => now()]);

        PreReviewSubmission::dispatch($submission);

        return response()->json([
            'type'           => 'success',
            'message'        => 'Lendo a submissão como o comitê leria…',
            'updatableSlots' => [PreReview::slot($submission->fresh())],
        ]);
    }

    /**
     * Polled while the pre-review runs. Cheap while pending on purpose — the
     * slot is only rendered once there is something to show.
     */
    public function preReviewStatus(Submission $submission): JsonResponse
    {
        $this->authorize('view', $submission);

        $pending = $submission->isPreReviewRunning();

        return response()->json([
            'pending'        => $pending,
            'updatableSlots' => $pending ? [] : [PreReview::slot($submission)],
        ]);
    }

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
