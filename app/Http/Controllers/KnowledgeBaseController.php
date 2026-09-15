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
use Illuminate\Support\Collection;
use Spatie\MediaLibrary\MediaCollections\Models\Media;
use Symfony\Component\HttpFoundation\BinaryFileResponse;
use Symfony\Component\HttpFoundation\StreamedResponse;

/**
 * The internal knowledge base (`/docs`) — the cadernos an admin has published,
 * readable by anybody signed in with a Leo account.
 *
 * SEMI-public, and the word is doing real work. The magic link beside it
 * (`PublicDocumentationController`) carries no identity at all: whoever holds
 * the URL reads the caderno, which is what makes it the right tool for a vendor
 * and the wrong one for "everybody here". This surface is the other half — an
 * account is required, Entra SSO is how most of them arrive
 * (`Auth\EntraController`), and the account it provisions is a `Reader`, which
 * reaches this screen and nothing else in the app.
 *
 * **The screen is `DocumentationReader`**, shared byte for byte with the magic
 * link. What this controller adds is the audience and one extra affordance: a
 * caderno SWITCHER in the top bar, which the magic link cannot have because a
 * token grants exactly one caderno. Here the reader has an account, so "what
 * else is published" is a question that can honestly be answered.
 *
 * **Every endpoint re-asks whether the caderno is published**, including the
 * three that are reached by id rather than by browsing (`file`,
 * `diagramPicture`, `search`). That is the whole authorization of this surface
 * and it is deliberately NOT a policy: `NotebookPolicy` answers about the
 * caderno as an object of editing, and it says `true` for every `Viewer` —
 * including for the two hundred cadernos nobody published.
 *
 * An unpublished caderno 404s for an ADMIN too, which is a deliberate choice
 * rather than an oversight. `/docs` exists so that "what has been published"
 * can be answered by looking at it; a surface that shows more to the person who
 * decides what is on it cannot answer that question. An admin previewing before
 * they publish reads the same text at `notebooks/{notebook}/{page}`.
 */
class KnowledgeBaseController extends Controller
{
    public function __construct(
        private readonly DocumentationReader $reader,
        private readonly DocumentationPageService $pages,
    ) {}

    /**
     * The landing: every published caderno, as a card.
     *
     * A real screen rather than a redirect into the first caderno, because the
     * question somebody arriving at `/docs` has is usually "what is here" — and
     * because there is a state a redirect cannot express, which is that nothing
     * has been published yet.
     */
    public function index(): View
    {
        return view('docs.index', [
            'notebooks' => $this->published()
                ->loadCount([
                    'pages as documented_count' => fn ($q) => $q
                        ->whereNotNull('documentation')->where('documentation', '<>', ''),
                ])
                ->map(fn (Notebook $notebook) => [
                    'name'       => $notebook->name,
                    'url'        => $notebook->knowledgeBaseUrl(),
                    'documented' => $notebook->documented_count,
                    'solutions'  => $notebook->solutions->pluck('name')->all(),
                ]),
        ]);
    }

    /** First page of the caderno's tree (or none, if it has no page yet). */
    public function notebook(Notebook $notebook): View
    {
        $this->guard($notebook);

        return $this->render($notebook, $this->pages->firstPage($notebook));
    }

    /**
     * One page.
     *
     * `{page}` IS model-bound here, unlike on the magic link — the route is
     * `scopeBindings()`ed, so it resolves through `Notebook::pages()` and a
     * slug belonging to another caderno 404s instead of being found globally
     * (a page slug is unique per caderno, never across them).
     */
    public function page(Notebook $notebook, DocumentationPage $page): View
    {
        $this->guard($notebook);

        return $this->render($notebook, $page);
    }

    /** The command palette, over this caderno's corpus and no other. */
    public function search(SearchDocumentationRequest $request, Notebook $notebook, DocumentationSearchService $search): JsonResponse
    {
        $this->guard($notebook);

        $payload = $this->reader->resolveSearchUrls(
            $search->search(
                $notebook,
                (string) ($request->validated('q') ?? ''),
                (array) ($request->validated('filter') ?? []),
            ),
            ReaderUrls::knowledgeBase($notebook),
        );

        return response()->json([
            'total'          => $payload['total'],
            'updatableSlots' => [SearchResults::slot($payload)],
        ]);
    }

    /**
     * Reveals ONE protected value of a page in a published caderno.
     *
     * Identical rules to every other surface (`RevealPageSecret`): an admin
     * reads it outright, everybody else types the caderno's secret code, and
     * five attempts per reader per twelve hours. Counted by USER id here rather
     * than by IP — there is an identity on this surface, and counting by IP
     * would have a whole office share one allowance.
     */
    public function revealSecret(
        RevealPageSecretRequest $request,
        Notebook $notebook,
        DocumentationPage $page,
        int $index,
        RevealPageSecret $reveal,
    ): JsonResponse {
        $this->guard($notebook);

        return response()->json([
            'value' => $reveal->handle(
                $notebook,
                $page,
                $index,
                $request->validated()['code'] ?? null,
                $request->user(),
                'user' . $request->user()->id,
            ),
        ]);
    }

    /**
     * Media embedded in a page of this caderno.
     *
     * A route of its own rather than `files.show`, and the reason is specific
     * to this surface: a `/docs` reader IS authenticated, so `files.show` would
     * happily answer them — with any documentation media in the app, including
     * pages of cadernos nobody published. `MediaController::show()` authorizes
     * by COLLECTION NAME alone, which is the right rule for somebody who may
     * read the whole inventory and the wrong one for a `Reader`.
     */
    public function file(Notebook $notebook, Media $media): BinaryFileResponse
    {
        $this->guard($notebook);

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

    /** The picture of a diagram this caderno cites — authorised by the citation. */
    public function diagramPicture(Notebook $notebook, Diagram $diagram): StreamedResponse
    {
        $this->guard($notebook);

        return DiagramCitation::stream($notebook, $diagram);
    }

    /**
     * The cadernos `/docs` shows, ordered by name.
     *
     * @return Collection<int, Notebook>
     */
    public static function publishedNotebooks(): Collection
    {
        return Notebook::query()
            ->published()
            ->with('solutions:id,name')
            ->orderBy('name')
            ->get();
    }

    /** @return Collection<int, Notebook> */
    private function published(): Collection
    {
        return self::publishedNotebooks();
    }

    /**
     * 404 for a caderno this surface does not carry.
     *
     * A 404 and not a 403: `/docs/{slug}` is addressable by guessing, and
     * telling somebody "that caderno exists, you just may not read it" is a
     * disclosure the screen has no reason to make.
     */
    private function guard(Notebook $notebook): void
    {
        abort_unless($notebook->isPublished(), 404);
    }

    private function render(Notebook $notebook, ?DocumentationPage $current): View
    {
        return view('docs.reader', [
            ...$this->reader->payload($notebook, $current, ReaderUrls::knowledgeBase($notebook)),
            // The switcher. Only this surface has one: a magic-link token
            // grants exactly one caderno, so there is nothing to switch to.
            'published' => $this->published(),
        ]);
    }
}
