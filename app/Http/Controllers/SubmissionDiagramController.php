<?php

namespace App\Http\Controllers;

use App\Http\Requests\StoreSubmissionDiagramUploadRequest;
use App\Models\Submission;
use App\Models\SubmissionDiagram;
use App\Support\Documentation\PictureResponse;
use App\View\Components\Submissions\Checklist;
use App\View\Components\Submissions\Diagrams;
use App\View\Components\Submissions\StageStrip;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\BinaryFileResponse;

/**
 * A submission's four pictures — AS IS, TO BE and the two C4 views
 * (`App\Enums\SubmissionDiagramKind`), each an image the person uploads.
 */
class SubmissionDiagramController extends Controller
{
    /**
     * Serves the slot's picture.
     *
     * Its own route because the collection is not `docs`, and
     * `MediaController::show()` (`/files/{id}`) serves only that one. It lives
     * behind the same `view` permission as the submission itself.
     */
    public function showPicture(Request $request, Submission $submission, SubmissionDiagram $diagram): BinaryFileResponse
    {
        $this->authorize('view', $diagram);

        $media = $diagram->picture();

        abort_if($media === null, 404);

        return PictureResponse::for($media, $request);
    }

    public function storeUpload(StoreSubmissionDiagramUploadRequest $request, Submission $submission, SubmissionDiagram $diagram): JsonResponse
    {
        $diagram->addMediaFromRequest('image')->toMediaCollection(SubmissionDiagram::UPLOAD_COLLECTION);

        return $this->saved($submission, 'Diagrama anexado.');
    }

    public function destroyUpload(Request $request, Submission $submission, SubmissionDiagram $diagram): JsonResponse
    {
        $this->authorize('update', $diagram);

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
