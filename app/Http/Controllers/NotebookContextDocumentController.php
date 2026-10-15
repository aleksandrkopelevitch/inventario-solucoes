<?php

namespace App\Http\Controllers;

use App\Actions\Documentation\AttachContextText;
use App\Actions\Documentation\RenameContextDocument;
use App\Http\Requests\StoreContextDocumentRequest;
use App\Http\Requests\UpdateContextDocumentRequest;
use App\Models\Notebook;
use App\Services\Documentation\ContextDocumentResolver;
use App\View\Components\Documentation\ContextDocuments;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
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
    /**
     * How much of a text document the preview reads. The preview is for
     * recognising a document, not for reading a 20 MB CSV in a modal.
     */
    private const PREVIEW_BYTES = 200_000;

    public function __construct(
        private readonly AttachContextText $texts,
        private readonly RenameContextDocument $rename,
    ) {}

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

    /** Renamed from the preview modal; the extension is kept (see the action). */
    public function update(UpdateContextDocumentRequest $request, Notebook $notebook, Media $media): JsonResponse
    {
        $this->assertBelongsTo($notebook, $media);

        $this->rename->handle($media, $request->validated('name'));

        return response()->json([
            'type'           => 'success',
            'message'        => "Documento renomeado para \"{$media->file_name}\".",
            'updatableSlots' => [ContextDocuments::slot($notebook->fresh())],
        ]);
    }

    /**
     * The file itself, or — over AJAX — the preview modal clicking a pill opens
     * in `#main-modal`. The modal points its <img>/<iframe> back at this same
     * action without JSON, which is what serves the bytes.
     */
    public function show(Request $request, Notebook $notebook, Media $media): JsonResponse|BinaryFileResponse
    {
        $this->authorize('update', $notebook);
        $this->assertBelongsTo($notebook, $media);

        if ($request->wantsJson()) {
            return response()->json([
                'content' => view('documentation.context-document-preview', [
                    'notebook' => $notebook,
                    'media'    => $media,
                    ...$this->preview($media),
                ])->render(),
            ]);
        }

        return response()->file($media->getPath(), [
            'Content-Type'        => $media->mime_type,
            'Content-Disposition' => 'inline; filename="' . addslashes($media->file_name) . '"',
        ]);
    }

    /**
     * What the modal shows: an image or a PDF is drawn by the browser from the
     * file URL; a text document is read here (the head of it), and a JSON one is
     * pretty-printed when it arrived whole — a pasted pipeline is stored
     * minified, one line no one can read.
     *
     * @return array{kind: string, text: ?string, truncated: bool}
     */
    private function preview(Media $media): array
    {
        $extension = strtolower((string) $media->extension);
        $mime = (string) $media->mime_type;

        if (str_starts_with($mime, 'image/')) {
            return ['kind' => 'image', 'text' => null, 'truncated' => false];
        }

        if ($mime === 'application/pdf' || $extension === 'pdf') {
            return ['kind' => 'pdf', 'text' => null, 'truncated' => false];
        }

        if (! in_array($extension, ContextDocumentResolver::TEXT_EXTENSIONS, true) && ! str_starts_with($mime, 'text/')) {
            return ['kind' => 'other', 'text' => null, 'truncated' => false];
        }

        $truncated = (int) $media->size > self::PREVIEW_BYTES;
        // mb_strcut never splits a multibyte character at the cut.
        $text = mb_strcut((string) file_get_contents($media->getPath(), false, null, 0, self::PREVIEW_BYTES), 0, self::PREVIEW_BYTES, 'UTF-8');

        if ($extension === 'json' && ! $truncated && json_validate($text)) {
            $text = json_encode(json_decode($text), JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
        }

        return ['kind' => 'text', 'text' => $text, 'truncated' => $truncated];
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
