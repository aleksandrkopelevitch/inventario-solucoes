<?php

namespace App\Http\Controllers\Concerns;

use App\Contracts\Documentable;
use App\Http\Requests\SaveDocumentationRequest;
use App\Http\Requests\UploadDocumentationMediaRequest;
use App\Models\Notebook;
use App\Support\Documentation\PageLinks;
use App\Support\Documentation\SecretText;
use App\Support\GitbookRenderer;
use Illuminate\Contracts\View\View;
use Illuminate\Http\JsonResponse;

/**
 * Logic for the block-based documentation editor (Editor.js): saving the
 * Markdown, uploading embedded media, and assembling the screen.
 *
 * It is a trait rather than controller methods because it was once shared by
 * two page controllers, one per kind of container. There is one now
 * (`NotebookPageController`) — the trait stays because the split it draws is
 * still the right one: the controller resolves route context, this assembles
 * the editor.
 */
trait EditsDocumentation
{
    /**
     * Assembles the editor page (same view for every resource). Whoever can
     * WRITE (admin or editor) sees Editor.js; everyone else sees the read-only
     * render (GitbookRenderer), decided client-side via `canEdit`. Neither of
     * them receives the page's PROTECTED VALUES — see `documentation` below.
     *
     * `$notebook` is the caderno the page belongs to. Its name titles the pages
     * rail and, once that rail is collapsed, becomes the "Caderno › Página"
     * crumb in the top bar; the ↗ beside it is the only way back to that record
     * (there is no back arrow — it pointed at the same place without ever
     * naming it). It is also what resolves the page's INTERNAL links: a
     * `page:{slug}` destination means a page of THIS caderno (see PageLinks).
     *
     * @param  array{save: string, upload: string}  $urls
     */
    protected function documentationView(
        Documentable $model,
        array $urls,
        string $eyebrow,
        string $pageLabel,
        Notebook $notebook,
    ): View {
        $canEdit = request()->user()->can('update', $model);

        return view('documentation.edit', [
            'title'         => $model->documentationTitle(),
            'eyebrow'       => $eyebrow,
            'pageLabel'     => $pageLabel,
            'notebookLabel' => $notebook->name,
            'notebookUrl'   => route('notebooks.show', $notebook),
            'saveUrl'       => $urls['save'],
            'uploadUrl'     => $urls['upload'],
            // The EDITOR's source, and the protected values are MASKED in it
            // for everybody — an admin included.
            //
            // The raw Markdown is what the editor round-trips, so an unmasked
            // copy would put every protected value on screen (and into the
            // chat's `existing_content`, and into "Copiar Markdown") the moment
            // someone opened the page. Masking only the people who may not read
            // them would have worked too, and this is stricter on purpose: the
            // plaintext then leaves the server through exactly one door
            // (App\Actions\Documentation\RevealPageSecret), one value per
            // request, for every audience there is. An admin unlocks a value by
            // clicking its lock and typing nothing; nobody reads a page's worth
            // of credentials by opening a screen.
            //
            // `persistDocumentation()` puts the real bytes back on save.
            'documentation' => SecretText::mask($model->documentation),
            'canEdit'       => $canEdit,
            // Only users who can't edit receive the already-rendered HTML
            // (the editor builds its own from the raw Markdown client-side).
            // `PageLinks::internal()` and not `none()`: a reader who is signed in
            // follows a `page:` link to the app's own URL for that page. The
            // magic-link reader resolves the same construct against its token
            // instead (PublicDocumentationController) — which is the whole
            // reason the Markdown stores a slug rather than an address.
            'renderedHtml' => $canEdit
                ? ''
                : app(GitbookRenderer::class)->render($model->documentation, pageLinks: PageLinks::internal($notebook)),
        ]);
    }

    /**
     * Saves the Markdown (+ GitBook notation) serialized by the editor.
     *
     * Protected values are restored from what is still in the database, ALWAYS
     * — not only when the person editing was shown markers. An admin edits the
     * real values, but the Documentation Assistant is fed masked content in
     * every case, so an admin applying its draft is saving markers too. Getting
     * this conditional wrong writes `[[SECRET-1]]` into the page as literal
     * text, and the value it stood for is gone.
     */
    protected function persistDocumentation(SaveDocumentationRequest $request, Documentable $model): JsonResponse
    {
        $model->update([
            'documentation' => SecretText::restore(
                $request->validated()['documentation'] ?? null,
                $model->documentation,
            ),
        ]);

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
