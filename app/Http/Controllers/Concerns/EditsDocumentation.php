<?php

namespace App\Http\Controllers\Concerns;

use App\Contracts\Documentable;
use App\Http\Requests\SaveDocumentationRequest;
use App\Http\Requests\UploadDocumentationMediaRequest;
use App\Support\GitbookRenderer;
use Illuminate\Contracts\View\View;
use Illuminate\Http\JsonResponse;

/**
 * Logic shared by the block-based documentation editor (Editor.js) across
 * the documentable resources (Solution, Integration). Each controller
 * resolves its own model + route context (save/upload/back URLs,
 * breadcrumb) and delegates saving the Markdown, uploading media, and
 * assembling the page to this trait.
 */
trait EditsDocumentation
{
    /**
     * Assembles the editor page (same view for every resource). Admins see
     * Editor.js; other users see the read-only render (GitbookRenderer),
     * decided client-side via `canEdit`.
     *
     * `$containerLabel`/`$containerUrl` are what the page BELONGS to — the
     * Solution, or the standalone group. They title the pages rail and, once
     * that rail is collapsed, become the "Solução › Página" crumb in the top
     * bar; the ↗ beside them is the only way back to that record (there is no
     * back arrow — it pointed at the same place without ever naming it).
     *
     * @param  array{save: string, upload: string}  $urls
     */
    protected function documentationView(
        Documentable $model,
        array $urls,
        string $eyebrow,
        string $pageLabel,
        string $containerLabel,
        string $containerUrl,
    ): View {
        $canEdit = request()->user()->can('update', $model);

        return view('documentation.edit', [
            'title'          => $model->documentationTitle(),
            'eyebrow'        => $eyebrow,
            'pageLabel'      => $pageLabel,
            'containerLabel' => $containerLabel,
            'containerUrl'   => $containerUrl,
            'saveUrl'        => $urls['save'],
            'uploadUrl'      => $urls['upload'],
            'documentation'  => $model->documentation,
            'canEdit'        => $canEdit,
            // Only users who can't edit receive the already-rendered HTML
            // (the editor builds its own from the raw Markdown client-side).
            'renderedHtml' => $canEdit ? '' : app(GitbookRenderer::class)->render($model->documentation),
        ]);
    }

    /** Saves the Markdown (+ GitBook notation) serialized by the editor. */
    protected function persistDocumentation(SaveDocumentationRequest $request, Documentable $model): JsonResponse
    {
        $model->update(['documentation' => $request->validated()['documentation'] ?? null]);

        return response()->json([
            'type'    => 'success',
            'message' => 'Documentação salva.',
        ]);
    }

    /**
     * Receives an image/file from the editor and stores it in the model's
     * `docs` collection. Response in the format expected by Editor.js's
     * Image/Attaches plugins: `{success: 1, file: {url, ...}}`. The url is
     * the authenticated `files.show` route (/files/{id}); the serializer
     * uses `mediaId` to rewrite it as /files/{id} in the Markdown.
     *
     * Two mutually exclusive paths (see UploadDocumentationMediaRequest):
     * - `file`: multipart (upload/drag/paste blob) — Image and Attaches.
     * - `url`:  image pasted from an external site (Image plugin only, via
     *   byUrl). We download and re-host it, restricted to image MIMEs, to
     *   never leave an <img> pointing at a third-party domain.
     */
    protected function storeDocumentationMedia(UploadDocumentationMediaRequest $request, Documentable $model): JsonResponse
    {
        $media = $request->filled('url')
            ? $model->addMediaFromUrl(
                $request->input('url'),
                'image/jpeg', 'image/png', 'image/gif', 'image/webp', 'image/svg+xml',
            )->toMediaCollection(Documentable::DOCS_COLLECTION)
            : $model->addMediaFromRequest('file')->toMediaCollection(Documentable::DOCS_COLLECTION);

        return response()->json([
            'success' => 1,
            'file'    => [
                'url'       => route('files.show', $media),
                'mediaId'   => $media->id,
                'name'      => $media->file_name,
                'title'     => $media->file_name,
                'size'      => $media->size,
                'extension' => $media->extension,
            ],
        ]);
    }
}
