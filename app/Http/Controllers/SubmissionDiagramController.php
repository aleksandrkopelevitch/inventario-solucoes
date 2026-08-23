<?php

namespace App\Http\Controllers;

use App\Http\Controllers\Concerns\EditsChain;
use App\Http\Requests\AddChainEdgeRequest;
use App\Http\Requests\AddChainImageRequest;
use App\Http\Requests\AddChainNodeRequest;
use App\Http\Requests\RemoveChainEdgeRequest;
use App\Http\Requests\RemoveChainNodeRequest;
use App\Http\Requests\RetargetChainEdgeRequest;
use App\Http\Requests\SaveChainLayoutRequest;
use App\Http\Requests\StoreSubmissionDiagramUploadRequest;
use App\Http\Requests\UpdateChainNodeRequest;
use App\Http\Requests\UpdateChainProtocolRequest;
use App\Models\Submission;
use App\Models\SubmissionDiagram;
use App\View\Components\Submissions\Checklist;
use App\View\Components\Submissions\Diagrams;
use App\View\Components\Submissions\StageStrip;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\StreamedResponse;

/**
 * A submission's four drawings — the AS IS / TO BE canvases and the two
 * uploaded C4 views (`App\Enums\SubmissionDiagramKind`).
 *
 * The chain endpoints are the SAME nine the integrations use, running the
 * same code (`Concerns\EditsChain`) against a different `ChainCanvas`. The
 * canvas client cannot tell the two apart: every URL it calls arrives inside
 * the graph payload it was drawn from, so nothing in
 * `integration-viz.js` knows a submission exists.
 *
 * What is genuinely different is what happens after a write: an Integration
 * re-derives participants/source/target/direction, a submission's drawing
 * derives NOTHING (`SubmissionDiagram::afterChainMutation()`). A proposal is
 * a thing being argued about; letting a rejected one write into the catalog
 * is the drift this module exists to remove.
 */
class SubmissionDiagramController extends Controller
{
    use EditsChain;

    /**
     * The canvas, on its own full-height page.
     *
     * A page rather than a panel inside the workbench, for the same reason an
     * integration's canvas has one: it is a pan/zoom surface with a toolbar
     * and a fullscreen mode, and 340px of a tab is not somewhere anyone can
     * draw an architecture.
     */
    public function edit(Submission $submission, SubmissionDiagram $diagram)
    {
        $this->authorize('view', $diagram);
        abort_unless($diagram->kind->isDrawn(), 404);

        return view('submissions.diagram', [
            'submission' => $submission,
            'diagram'    => $diagram,
        ]);
    }

    /* ------------------------------------------------------------------ */
    /*  Chain — identical semantics to the integration canvas */
    /* ------------------------------------------------------------------ */

    public function saveLayout(SaveChainLayoutRequest $request, Submission $submission, SubmissionDiagram $diagram): JsonResponse
    {
        return $this->saveChainLayout(
            $diagram,
            $request->safe()->only(['nodes', 'edges', 'comments', 'lanes', 'notes', 'theme']),
        );
    }

    public function updateNode(UpdateChainNodeRequest $request, Submission $submission, SubmissionDiagram $diagram, int $node): JsonResponse
    {
        return $this->updateChainNode($diagram, $request->validated(), $node);
    }

    public function removeNode(RemoveChainNodeRequest $request, Submission $submission, SubmissionDiagram $diagram, int $node): JsonResponse
    {
        return $this->removeChainNode($diagram, $node);
    }

    public function updateProtocol(UpdateChainProtocolRequest $request, Submission $submission, SubmissionDiagram $diagram, int $edge): JsonResponse
    {
        return $this->updateChainProtocol($diagram, $request->validated(), $edge);
    }

    public function addNode(AddChainNodeRequest $request, Submission $submission, SubmissionDiagram $diagram): JsonResponse
    {
        return $this->addChainNode($diagram, $request->validated());
    }

    public function addImageNode(AddChainImageRequest $request, Submission $submission, SubmissionDiagram $diagram): JsonResponse
    {
        return $this->addChainImageNode($diagram, $request->file('image'));
    }

    public function retargetEdge(RetargetChainEdgeRequest $request, Submission $submission, SubmissionDiagram $diagram, int $edge): JsonResponse
    {
        return $this->retargetChainEdge($diagram, $request->validated(), $edge);
    }

    public function addEdge(AddChainEdgeRequest $request, Submission $submission, SubmissionDiagram $diagram): JsonResponse
    {
        return $this->addChainEdge($diagram, $request->validated());
    }

    public function removeEdge(RemoveChainEdgeRequest $request, Submission $submission, SubmissionDiagram $diagram, int $edge): JsonResponse
    {
        return $this->removeChainEdge($diagram, $edge);
    }

    /* ------------------------------------------------------------------ */
    /*  Pictures */
    /* ------------------------------------------------------------------ */

    /**
     * Serves whichever picture this slot holds — the canvas's capture for a
     * drawn kind, the upload for a C4 one.
     *
     * Its own route because neither collection is `docs`, and
     * `MediaController::show()` (`/files/{id}`) serves only that one. Both
     * live behind the same `view` permission as the submission itself.
     */
    public function showPicture(Submission $submission, SubmissionDiagram $diagram): StreamedResponse
    {
        $this->authorize('view', $diagram);

        $media = $diagram->picture();

        abort_if($media === null, 404);

        return $media->toResponse(request());
    }

    /**
     * The canvas's own rendered PNG, republished after every successful
     * layout save — fire-and-forget from the client, exactly as on the
     * integration canvas.
     *
     * A DERIVED artifact and never an input: it must not touch `chain` or
     * `viz_layout`. It exists so the deck can print the architecture without a
     * browser in the loop, which is what keeps the canvas the one place a
     * diagram is edited.
     */
    public function storePicture(Request $request, Submission $submission, SubmissionDiagram $diagram): JsonResponse
    {
        $this->authorize('update', $diagram);
        abort_unless($diagram->kind->isDrawn(), 404);

        $request->validate(['image' => ['required', 'image', 'mimes:png', 'max:8192']]);

        $diagram->addMediaFromRequest('image')->toMediaCollection($diagram->chainDiagramCollection());

        return response()->json(['type' => 'success', 'message' => 'Diagrama publicado.']);
    }

    /**
     * The uploaded picture of a C4 kind.
     *
     * C4 is a modelling notation and the canvas is a free graph — bending one
     * into the other would mean inventing a notation and calling it C4. So
     * these two slots take the drawing the architect already made, in a tool
     * that speaks it.
     */
    public function storeUpload(StoreSubmissionDiagramUploadRequest $request, Submission $submission, SubmissionDiagram $diagram): JsonResponse
    {
        abort_if($diagram->kind->isDrawn(), 404);

        $diagram->addMediaFromRequest('image')->toMediaCollection(SubmissionDiagram::UPLOAD_COLLECTION);

        return $this->saved($submission, 'Diagrama anexado.');
    }

    public function destroyUpload(Request $request, Submission $submission, SubmissionDiagram $diagram): JsonResponse
    {
        $this->authorize('update', $diagram);
        abort_if($diagram->kind->isDrawn(), 404);

        $diagram->clearMediaCollection(SubmissionDiagram::UPLOAD_COLLECTION);

        return $this->saved($submission, 'Diagrama removido.');
    }

    /**
     * Filling or clearing a diagram slot moves the committee's own checklist
     * item ("Diagramas de arquitetura anexados"), which is derived rather than
     * ticked by hand — so the checklist and the stage strip come back with the
     * diagrams card, from whichever tab the person is on.
     */
    private function saved(Submission $submission, string $message): JsonResponse
    {
        $submission->load(['diagrams.media', 'sections', 'sources', 'solution']);

        return response()->json([
            'type'           => 'success',
            'message'        => $message,
            'updatableSlots' => [
                Diagrams::slot($submission),
                Checklist::slot($submission),
                StageStrip::slot($submission),
            ],
        ]);
    }
}
