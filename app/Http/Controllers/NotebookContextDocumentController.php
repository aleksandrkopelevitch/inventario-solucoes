<?php

namespace App\Http\Controllers;

use App\Actions\Documentation\AttachContextText;
use App\Http\Requests\StoreContextDocumentRequest;
use App\Models\Notebook;
use App\View\Components\Documentation\ContextDocuments;
use Illuminate\Http\JsonResponse;
use Spatie\MediaLibrary\MediaCollections\Models\Media;
use Symfony\Component\HttpFoundation\BinaryFileResponse;

/**
 * Context documents for a Caderno (the `context_documents` collection), read by
 * the documentation's "Assiste IA". They belong to the notebook and are shared
 * by every page in it — which is also why they moved off `Solution` with the
 * container swap: the chat is about a page, and a page always has a notebook,
 * while it may have no solution at all.
 *
 * Serving lives here and not in MediaController, which only releases the `docs`
 * collection.
 */
class NotebookContextDocumentController extends Controller
{
    public function __construct(private readonly AttachContextText $texts) {}

    /**
     * A picked file, or a long paste from the composer. Both end up as one
     * context document of this caderno — see AttachContextText for why a paste
     * lands here rather than on the conversation.
     */
    public function store(StoreContextDocumentRequest $request, Notebook $notebook): JsonResponse
    {
        $media = $request->filled('text')
            ? $this->texts->handle($notebook, $request->validated('text'))
            : $notebook->addMediaFromRequest('file')->toMediaCollection(Notebook::CONTEXT_COLLECTION);

        return response()->json([
            'type'           => 'success',
            'message'        => "Documento de contexto \"{$media->file_name}\" anexado.",
            'updatableSlots' => [ContextDocuments::slot($notebook->fresh())],
        ]);
    }

    public function destroy(Notebook $notebook, Media $media): JsonResponse
    {
        $this->authorize('update', $notebook);
        $this->assertBelongsTo($notebook, $media);

        $media->delete();

        return response()->json([
            'type'           => 'success',
            'message'        => 'Documento de contexto removido.',
            'updatableSlots' => [ContextDocuments::slot($notebook->fresh())],
        ]);
    }

    public function show(Notebook $notebook, Media $media): BinaryFileResponse
    {
        $this->authorize('update', $notebook);
        $this->assertBelongsTo($notebook, $media);

        return response()->file($media->getPath(), [
            'Content-Type'        => $media->mime_type,
            'Content-Disposition' => 'inline; filename="' . addslashes($media->file_name) . '"',
        ]);
    }

    /** 404 if the media is not a context document belonging to this caderno. */
    private function assertBelongsTo(Notebook $notebook, Media $media): void
    {
        abort_unless(
            $media->collection_name === Notebook::CONTEXT_COLLECTION
                && $media->model_type === $notebook->getMorphClass()
                && (int) $media->model_id === $notebook->id,
            404,
        );
    }
}
