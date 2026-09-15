<?php

namespace App\Http\Controllers;

use App\Actions\Documentation\RevealPageSecret;
use App\Contracts\Documentable;
use App\Http\Requests\RevealPageSecretRequest;
use App\Http\Requests\SearchDocumentationRequest;
use App\Models\Diagram;
use App\Models\DocumentationPage;
use App\Models\Notebook;
use App\Services\Documentation\DocumentationReader;
use App\Services\DocumentationPageService;
use App\Services\DocumentationSearchService;
use App\Support\Documentation\DiagramCitation;
use App\Support\Documentation\ReaderUrls;
use App\View\Components\Documentation\SearchResults;
use Illuminate\Contracts\View\View;
use Illuminate\Http\JsonResponse;
use Spatie\MediaLibrary\MediaCollections\Models\Media;
use Symfony\Component\HttpFoundation\BinaryFileResponse;
use Symfony\Component\HttpFoundation\StreamedResponse;

/**
 * PUBLIC documentation for a caderno ("magic link"), no auth. Access is via an
 * opaque token in the URL (`Notebook::public_token`); from it, shows that
 * notebook's page tree (1..N, GitBook-style) in a dedicated `public-docs`
 * layout (top bar + side index).
 *
 * What is shared is ONE notebook, never a solution's whole documentation. The
 * token used to hang off `Solution`, and moving it here is what keeps the
 * shared surface a thing somebody chose to share: a caderno linked to three
 * solutions is still one link to one body of text, and linking a notebook to a
 * solution never publishes anything.
 *
 * Tokens generated before the swap were carried across verbatim by the
 * migration, so links already in the wild keep resolving.
 *
 * **The screen itself lives in `DocumentationReader`**, which
 * `KnowledgeBaseController` renders too. This controller is now the half that
 * is genuinely about the magic link: resolving a token, and refusing to serve
 * anything the token does not grant. Everything about how a page LOOKS is
 * shared, deliberately — see that service.
 *
 * Embedded media (`/files/{id}` in the Markdown) is rewritten to a dedicated
 * public route, validated against this notebook's own pages — the
 * authenticated `files.show` route doesn't serve visitors.
 */
class PublicDocumentationController extends Controller
{
    public function __construct(private readonly DocumentationReader $reader) {}

    /** First page of the tree (or none, if the caderno has no page yet). */
    public function notebook(string $token): View
    {
        $notebook = $this->resolve($token);

        return $this->render($notebook, app(DocumentationPageService::class)->firstPage($notebook));
    }

    /**
     * `$slug` is NOT resolved via route-model-binding — a DocumentationPage's
     * slug is only unique WITHIN its notebook (see the composite unique on
     * `documentation_pages`), never globally. Two cadernos can each have a page
     * called "test"; a global `{page:slug}` binding would grab the lowest id
     * (belonging to another caderno) and 404 for the wrong owner. Scoping the
     * query by the Notebook resolved from the token avoids the ambiguity
     * entirely.
     */
    public function page(string $token, string $slug): View
    {
        $notebook = $this->resolve($token);
        $page = $notebook->pages()->where('slug', $slug)->firstOrFail();

        return $this->render($notebook, $page);
    }

    /**
     * The command palette's backing endpoint (`docs-search.js`).
     *
     * Scoped to the token's own caderno and nothing else: the service is handed
     * THIS notebook, so a visitor can never reach another caderno's pages
     * through it, however the query is shaped.
     */
    public function search(SearchDocumentationRequest $request, string $token, DocumentationSearchService $search): JsonResponse
    {
        $notebook = $this->resolve($token);

        $payload = $this->reader->resolveSearchUrls(
            $search->search(
                $notebook,
                (string) ($request->validated('q') ?? ''),
                (array) ($request->validated('filter') ?? []),
            ),
            ReaderUrls::shared($notebook, $token),
        );

        // One slot, not a JSON list the browser turns into HTML: the hits are
        // page text somebody authored, and rendering them through Blade is
        // what escapes it (see the SearchResults docblock). `total` rides
        // along for tests and for anything that only wants the count.
        return response()->json([
            'total'          => $payload['total'],
            'updatableSlots' => [SearchResults::slot($payload)],
        ]);
    }

    /**
     * Reveals ONE protected value of a page in the shared caderno.
     *
     * The token is not the authorization here, and that is the whole point of
     * the feature: it resolves WHICH caderno may be asked about, and the
     * caderno's secret code is what unlocks a value inside it. A visitor is
     * counted by IP (there is no identity on this surface) and gets the same
     * five attempts per twelve hours as a signed-in reader.
     *
     * `$slug` is scoped to this notebook for the same reason `page()` does it:
     * a page slug is unique per caderno, never globally.
     */
    public function revealSecret(
        RevealPageSecretRequest $request,
        string $token,
        string $slug,
        int $index,
        RevealPageSecret $reveal,
    ): JsonResponse {
        $notebook = $this->resolve($token);
        $page = $notebook->pages()->where('slug', $slug)->firstOrFail();

        return response()->json([
            'value' => $reveal->handle(
                $notebook,
                $page,
                $index,
                $request->validated()['code'] ?? null,
                // No user, ever — a signed-in admin who happens to be reading a
                // public link is a visitor here. Recognising them would make
                // the shared page's behaviour depend on who is looking at it,
                // which is exactly what `linkDiagrams: false` avoids upstream.
                null,
                'ip' . $request->ip(),
            ),
        ]);
    }

    public function file(string $token, Media $media): BinaryFileResponse
    {
        $notebook = $this->resolve($token);
        $owner = $media->model;

        $allowed = $media->collection_name === Documentable::DOCS_COLLECTION
            && $owner instanceof DocumentationPage
            && (int) $owner->notebook_id === $notebook->id;

        abort_unless($allowed, 404);

        return response()->file($media->getPath(), [
            'Content-Type'        => $media->mime_type,
            'Content-Disposition' => 'inline; filename="' . addslashes($media->file_name) . '"',
        ]);
    }

    /**
     * The picture of a diagram cited by this caderno.
     *
     * A citation renders the drawing's current PNG, and that image used to be
     * requested from `diagrams.picture.show` — which is behind auth, so every
     * cited diagram on a magic link rendered as a BROKEN image. Withholding the
     * "Abrir diagrama" link was right; letting the picture 302 to the login
     * screen was not the same thing.
     *
     * Authorised by CITATION, not by the diagram: the token grants this caderno,
     * and what this caderno shows is what its pages cite. A diagram nobody cited
     * here is a 404 even with a valid token, so the route can't be walked to
     * enumerate the drawing catalog.
     */
    public function diagramPicture(string $token, Diagram $diagram): StreamedResponse
    {
        return DiagramCitation::stream($this->resolve($token), $diagram);
    }

    private function resolve(string $token): Notebook
    {
        return Notebook::where('public_token', $token)->firstOrFail();
    }

    private function render(Notebook $notebook, ?DocumentationPage $current): View
    {
        return view('public.docs', $this->reader->payload(
            $notebook,
            $current,
            ReaderUrls::shared($notebook, (string) $notebook->public_token),
        ));
    }
}
