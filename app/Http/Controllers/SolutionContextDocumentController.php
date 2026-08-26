<?php

namespace App\Http\Controllers;

use App\Http\Requests\StoreContextDocumentRequest;
use App\Models\Solution;
use App\View\Components\Documentation\ContextDocuments;
use Illuminate\Http\JsonResponse;
use Spatie\MediaLibrary\MediaCollections\Models\Media;
use Symfony\Component\HttpFoundation\BinaryFileResponse;

/**
 * Context documents for a Solution (the `context_documents` collection),
 * reused by the documentation's "AI Assist". They are per-Solution and
 * shared across its pages and the docs of its diagrams. Serving lives
 * here and not in MediaController, which only releases the `docs` collection.
 */
class SolutionContextDocumentController extends Controller
{
    public function store(StoreContextDocumentRequest $request, Solution $solution): JsonResponse
    {
        $media = $solution->addMediaFromRequest('file')
            ->toMediaCollection(Solution::CONTEXT_COLLECTION);

        return response()->json([
            'type'           => 'success',
            'message'        => "Documento de contexto \"{$media->file_name}\" anexado.",
            'updatableSlots' => [ContextDocuments::slot($solution->fresh())],
        ]);
    }

    public function destroy(Solution $solution, Media $media): JsonResponse
    {
        $this->authorize('update', $solution);
        $this->assertBelongsTo($solution, $media);

        $media->delete();

        return response()->json([
            'type'           => 'success',
            'message'        => 'Documento de contexto removido.',
            'updatableSlots' => [ContextDocuments::slot($solution->fresh())],
        ]);
    }

    public function show(Solution $solution, Media $media): BinaryFileResponse
    {
        $this->authorize('update', $solution);
        $this->assertBelongsTo($solution, $media);

        return response()->file($media->getPath(), [
            'Content-Type'        => $media->mime_type,
            'Content-Disposition' => 'inline; filename="' . addslashes($media->file_name) . '"',
        ]);
    }

    /** 404 if the media is not a context document belonging to this Solution. */
    private function assertBelongsTo(Solution $solution, Media $media): void
    {
        abort_unless(
            $media->collection_name === Solution::CONTEXT_COLLECTION
                && $media->model_type === $solution->getMorphClass()
                && (int) $media->model_id === $solution->id,
            404,
        );
    }
}
