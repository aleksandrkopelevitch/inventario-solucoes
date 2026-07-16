<?php

namespace App\Http\Controllers;

use App\Http\Requests\StoreContextDocumentRequest;
use App\Models\Solution;
use App\View\Components\Documentation\ContextDocuments;
use Illuminate\Http\JsonResponse;
use Spatie\MediaLibrary\MediaCollections\Models\Media;
use Symfony\Component\HttpFoundation\BinaryFileResponse;

/**
 * Documentos de contexto de uma Solução (coleção `context_documents`),
 * reaproveitados pelo "Assiste IA" da documentação. São por Solução e
 * compartilhados entre as páginas dela e as docs das suas integrações. Servir
 * fica aqui e não no MediaController, que só libera a coleção `docs`.
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

    /** 404 se a mídia não for um documento de contexto desta Solução. */
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
