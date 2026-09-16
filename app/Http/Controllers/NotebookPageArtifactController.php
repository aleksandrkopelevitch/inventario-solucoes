<?php

namespace App\Http\Controllers;

use App\Enums\ArtifactDiagramType;
use App\Exceptions\PageArtifactFailed;
use App\Models\DocumentationPage;
use App\Models\Notebook;
use App\Services\Documentation\PageArtifactService;
use App\Support\Archify\ArchifyRunner;
use App\View\Components\Documentation\PageArtifacts;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Str;
use Spatie\MediaLibrary\MediaCollections\Models\Media;
use Symfony\Component\HttpFoundation\BinaryFileResponse;

/**
 * The four Archify diagrams a page can be turned into (see
 * `App\Enums\ArtifactDiagramType`), stored as media on the page and read in a
 * tab of their own.
 *
 * Its own controller rather than a method on `NotebookPageController` for the
 * same reason `NotebookPageDiagramController` is: what it writes is not the
 * page's text. Unlike that one, this DOES belong to the page — an artifact is
 * a picture of this prose and dies with it — which is why it is media on the
 * page rather than a row in another module.
 */
class NotebookPageArtifactController extends Controller
{
    public function __construct(
        private readonly PageArtifactService $artifacts,
        private readonly ArchifyRunner $archify,
    ) {}

    public function store(Notebook $notebook, DocumentationPage $page, ArtifactDiagramType $type): JsonResponse
    {
        $page->setRelation('notebook', $notebook);
        $this->authorize('update', $page);

        // Checked before the model call, not after: an operator-side problem
        // (no Node on the box, a half-deployed sidecar) must not cost a
        // generation and must not read as "the page said too little".
        if (! $this->archify->available()) {
            throw PageArtifactFailed::rendererUnavailable();
        }

        ['path' => $path, 'title' => $title] = $this->artifacts->render($page, $type);

        try {
            $page->addMedia($path)
                ->usingName($title)
                ->usingFileName(Str::slug($type->value . '-' . $title) . '.html')
                ->withCustomProperties(['type' => $type->value, 'title' => $title])
                ->toMediaCollection(DocumentationPage::ARTIFACTS_COLLECTION);
        } finally {
            // addMedia() MOVES the file on success; this covers the throw.
            @unlink($path);
        }

        return response()->json([
            'type'           => 'success',
            'message'        => $type->label() . ' gerado. Abra na barra lateral para conferir.',
            'updatableSlots' => [PageArtifacts::slot($page->fresh(), canEdit: true)],
            // The menu has to close itself. `toggle.js` closes a popover on an
            // OUTSIDE click, and the item that was just pressed is not one —
            // the same trap the assistant's attach menu needed `closeAttachMenu()`
            // for. There is no JS module on this bar to put that in, and the
            // AJAX contract already carries a `js` field for exactly this.
            // Deliberately only on SUCCESS: a refusal keeps the menu on screen,
            // which is where the person is if they want to try another type.
            'js' => "document.getElementById('docs-draw-menu')?.classList.add('hidden');",
        ]);
    }

    /**
     * The artifact itself.
     *
     * Served from this app's origin, which is the reason for every header
     * below. What is stored is a complete HTML document with its own inline
     * scripts — produced by a deterministic renderer from a validated spec, but
     * carrying labels that came out of a language model reading somebody's
     * page. `sandbox allow-scripts` (the CSP directive, not the iframe
     * attribute) gives the document a unique opaque origin: its scripts run, so
     * the diagram stays interactive, while it can read no cookie, no storage
     * and nothing else of this app's. The rest of the policy says out loud that
     * a self-contained artifact needs no network at all.
     */
    public function show(Notebook $notebook, DocumentationPage $page, Media $media): BinaryFileResponse
    {
        $page->setRelation('notebook', $notebook);
        $this->authorize('view', $page);

        abort_unless($this->belongsToPage($media, $page), 404);

        return response()->file($media->getPath(), [
            'Content-Type'            => 'text/html; charset=utf-8',
            'X-Content-Type-Options'  => 'nosniff',
            'Content-Security-Policy' => implode('; ', [
                "default-src 'none'",
                "style-src 'unsafe-inline'",
                "script-src 'unsafe-inline'",
                'img-src data: blob:',
                'font-src data:',
                "connect-src 'none'",
                'sandbox allow-scripts allow-downloads',
            ]),
        ]);
    }

    public function destroy(Notebook $notebook, DocumentationPage $page, Media $media): JsonResponse
    {
        $page->setRelation('notebook', $notebook);
        $this->authorize('update', $page);

        abort_unless($this->belongsToPage($media, $page), 404);

        $media->delete();

        return response()->json([
            'type'           => 'success',
            'message'        => 'Diagrama removido.',
            'updatableSlots' => [PageArtifacts::slot($page->fresh(), canEdit: true)],
        ]);
    }

    /**
     * Both halves matter. The owner, because `{media}` is an id in a URL and
     * nothing about it is scoped; and the COLLECTION, because a page's other
     * collection holds the images embedded in its Markdown — serving one of
     * those through this action would hand it the sandboxing headers of an
     * artifact and, worse, would mean this route can read anything attached to
     * a page rather than what it was written for.
     */
    private function belongsToPage(Media $media, DocumentationPage $page): bool
    {
        return $media->model_type === $page->getMorphClass()
            && $media->model_id === $page->getKey()
            && $media->collection_name === DocumentationPage::ARTIFACTS_COLLECTION;
    }
}
