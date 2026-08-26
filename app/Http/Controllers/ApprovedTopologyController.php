<?php

namespace App\Http\Controllers;

use App\Actions\Cati\ApplyApprovedTopology;
use App\Http\Requests\ApplyApprovedTopologyRequest;
use App\Models\ApprovedTopology;
use App\Models\Submission;
use App\View\Components\Submissions\Deliberation;
use App\View\Components\Submissions\TopologyHandoff;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * Closing the loop the committee opened: a topology it approved either lands on
 * a real Diagram or is declared unnecessary.
 *
 * Two outcomes on purpose, and they are not the same claim — "the catalog now
 * says this" and "the catalog was already right" mean different things to
 * whoever reads the history later.
 */
class ApprovedTopologyController extends Controller
{
    public function apply(
        ApplyApprovedTopologyRequest $request,
        Submission $submission,
        ApprovedTopology $topology,
        ApplyApprovedTopology $action,
    ): JsonResponse {
        $this->belongsToSubmission($topology, $submission);
        abort_unless($topology->isPending(), 409);

        $diagram = $action->handle(
            $topology,
            $request->user(),
            $request->targetDiagram(),
        );

        return $this->answered(
            $submission,
            "Topologia aplicada em “{$diagram->name}”.",
        );
    }

    public function dismiss(Request $request, Submission $submission, ApprovedTopology $topology, ApplyApprovedTopology $action): JsonResponse
    {
        $this->authorize('update', $submission);
        $this->belongsToSubmission($topology, $submission);
        abort_unless($topology->isPending(), 409);

        $action->dismiss($topology, $request->user(), $request->string('reason')->trim()->value() ?: null);

        return $this->answered($submission, 'Marcada como já refletida no catálogo.');
    }

    /**
     * The route is deliberately not scoped (see routes/web.php: the relation is
     * a HasOne, and `scopeBindings()` wants a plural), so ownership is checked
     * here — without it, a topology could be resolved through any submission.
     */
    private function belongsToSubmission(ApprovedTopology $topology, Submission $submission): void
    {
        abort_unless($topology->submission_id === $submission->id, 404);
    }

    private function answered(Submission $submission, string $message): JsonResponse
    {
        return response()->json([
            'type'           => 'success',
            'message'        => $message,
            'updatableSlots' => [
                TopologyHandoff::slot($submission->fresh()),
                // The deliberation card sits next to it and names the same
                // decision, so they must not disagree about its state.
                Deliberation::slot($submission->fresh()),
            ],
        ]);
    }
}
