<?php

namespace App\Http\Controllers;

use App\Actions\Documentation\RevealPageSecret;
use App\Http\Controllers\Concerns\AssistsDocumentation;
use App\Http\Controllers\Concerns\BuildsPagesNav;
use App\Http\Controllers\Concerns\EditsDocumentation;
use App\Http\Requests\MoveDocumentationPageRequest;
use App\Http\Requests\MoveDocumentationPageToNotebookRequest;
use App\Http\Requests\RevealPageSecretRequest;
use App\Http\Requests\SaveDocumentationPageTitleRequest;
use App\Http\Requests\SaveDocumentationRequest;
use App\Http\Requests\StoreDocumentationChatMessageRequest;
use App\Http\Requests\StoreDocumentationPageRequest;
use App\Http\Requests\UploadDocumentationMediaRequest;
use App\Models\DocumentationChat;
use App\Models\DocumentationChatMessage;
use App\Models\DocumentationPage;
use App\Models\Notebook;
use App\Services\DocumentationPageService;
use App\Services\DocumentationSearchService;
use App\View\Components\Documentation\PageTitle;
use App\View\Components\Solutions\Notebooks;
use Illuminate\Contracts\View\View;
use Illuminate\Http\JsonResponse;

/**
 * The page tree inside a caderno — a tree of 1..N pages, each edited by the
 * Editor.js block editor (Markdown + GitBook notation in
 * `documentation_pages.documentation`), and each optionally pointing at a
 * `Diagram` instead of a second editor existing for drawings.
 *
 * This is the merge of `SolutionDocumentationController` and
 * `DocumentationGroupPageController`, which were the same controller written
 * twice — once per kind of container. Everything that differed between them was
 * the route name and the label above the rail; everything that mattered was
 * already shared through the traits below.
 *
 * Thin — delegates to `EditsDocumentation` (per page), `LinksPageDiagram`,
 * `AssistsDocumentation` (the chat) and `DocumentationPageService` (tree rules:
 * create/rename/move/nest/delete, up to `DocumentationPage::MAX_DEPTH` levels).
 */
class NotebookPageController extends Controller
{
    use AssistsDocumentation, BuildsPagesNav, EditsDocumentation;

    public function __construct(private readonly DocumentationPageService $pages) {}

    public function store(StoreDocumentationPageRequest $request, Notebook $notebook): JsonResponse
    {
        $page = $this->pages->create($notebook, $request->validated()['title'], $request->parentPage());

        return response()->json([
            'type'     => 'success',
            'message'  => 'Página criada.',
            'redirect' => route('notebooks.pages.edit', [$notebook, $page]),
        ]);
    }

    public function edit(Notebook $notebook, DocumentationPage $page): View
    {
        // Avoids lazy loading $page->notebook inside DocumentationPagePolicy
        // (strict mode) — we already have it in hand via the route binding.
        $page->setRelation('notebook', $notebook);

        return $this->documentationView($page, [
            'save'   => route('notebooks.pages.update', [$notebook, $page]),
            'upload' => route('notebooks.pages.media', [$notebook, $page]),
        ],
            eyebrow: 'Caderno · ' . $notebook->name,
            pageLabel: $page->title,
            notebook: $notebook,
        )->with([
            'pagesNav' => $this->pagesNav($notebook, $page),
            // The rail's header renames the caderno in place — `?page=` so the
            // endpoint can hand the rail back around the right active row.
            'notebookRenameUrl' => route('notebooks.update', ['notebook' => $notebook, 'page' => $page->slug]),
            'notebookEditable'  => auth()->user()?->can('update', $notebook) ?? false,
            'createPageUrl'     => route('notebooks.pages.store', $notebook),
            'chatPanelUrl'      => route('notebooks.chat.panel', [$notebook, $page]),
            // Where a lock posts its code. A TEMPLATE, with the ordinal as a
            // placeholder `docs-secret.js` substitutes: the reader holds many
            // locks and the module must not build a path of its own (same rule
            // that keeps `chain-viz.js` free of routes) — so the one route()
            // call happens here.
            'secretRevealUrl' => route('notebooks.pages.secrets', [$notebook, $page, 'index' => '__INDEX__']),
            // Failed attempts are remembered per CADERNO, since that is what a
            // code unlocks.
            'secretScope' => $notebook->slug,
            // Whether this reader is asked for a code at all. A UX hint and
            // nothing more: `RevealPageSecret` decides for itself, so forging
            // the attribute buys a request that is refused for want of a code.
            'secretsUnlocked' => auth()->user()?->role->isAdmin() ?? false,
            'chatResume'      => $this->chatResumeFor($notebook, $page),
            // Navigation cards for this page's own sub-pages — see
            // x-documentation.child-pages for why an imported corpus needs them.
            'childPages' => $this->childPages($notebook, $page),
            // Sharing and the linked solutions both belong to the CADERNO, not
            // to this page — they sit in the toolbar and are the same for every
            // page of the tree.
            'notebook' => $notebook,
            // Drives the editable title in the top bar.
            'titlePage'   => $page,
            'breadcrumbs' => [
                ['label' => 'Cadernos', 'url' => route('notebooks.index')],
                ['label' => $notebook->name, 'url' => route('notebooks.show', $notebook)],
            ],
        ]);
    }

    /**
     * Saves the page's Markdown.
     *
     * The extra slots are the price of a notebook describing several solutions:
     * a save changes what every one of their detail cards should say about
     * coverage. `ajax-slot.js` no-ops on ids that aren't on the current page, so
     * sending one per linked solution is safe — and there is no other moment at
     * which those cards would learn.
     */
    public function update(SaveDocumentationRequest $request, Notebook $notebook, DocumentationPage $page): JsonResponse
    {
        $response = $this->persistDocumentation($request, $page);

        return $response->setData($response->getData(true) + [
            'updatableSlots' => $notebook->solutions
                ->map(fn ($solution) => Notebooks::slot($solution))
                ->all(),
        ]);
    }

    /**
     * A rename answers with SLOTS, not a redirect.
     *
     * The slug never follows the title (the URL is deliberately stable), so
     * there is nothing to navigate to — and the name shows in two places that
     * aren't in the same subtree: the top bar and the rail. It used to redirect
     * because the only way to rename was the rail's own hidden form, which
     * reloaded the screen to show the result.
     */
    public function rename(SaveDocumentationPageTitleRequest $request, Notebook $notebook, DocumentationPage $page): JsonResponse
    {
        $this->pages->rename($page, $request->validated()['title']);

        return response()->json([
            'type'           => 'success',
            'message'        => 'Página renomeada.',
            'updatableSlots' => [
                PageTitle::slot($notebook, $page->fresh()),
                $this->pagesNavSlot($notebook, $page->fresh()),
            ],
        ]);
    }

    public function destroy(Notebook $notebook, DocumentationPage $page): JsonResponse
    {
        $this->authorize('update', $notebook);

        $next = $this->pages->delete($page);

        return response()->json([
            'type'     => 'success',
            'message'  => 'Página excluída.',
            'redirect' => $next
                ? route('notebooks.pages.edit', [$notebook, $next])
                : route('notebooks.show', $notebook),
        ]);
    }

    public function move(MoveDocumentationPageRequest $request, Notebook $notebook, DocumentationPage $page): JsonResponse
    {
        $this->pages->move($page, $request->validated()['direction']);

        return response()->json([
            'type'    => 'success',
            'message' => match ($request->validated()['direction']) {
                'in'    => 'Página aninhada.',
                'out'   => 'Página promovida.',
                default => 'Ordem atualizada.',
            },
            'updatableSlots' => [$this->pagesNavSlot($notebook, $page->fresh())],
        ]);
    }

    /**
     * Moves the page out of this caderno and into another one — the reason the
     * GitBook import can land a whole space in one place and still end up tidy.
     * Answers with the page's NEW url: the page no longer exists at this one,
     * so a slot swap would leave the browser on a 404.
     */
    public function moveToNotebook(MoveDocumentationPageToNotebookRequest $request, Notebook $notebook, DocumentationPage $page): JsonResponse
    {
        $destination = $request->destination();
        // The destination is a different record with its own policy — a 403,
        // not a validation error (see the request's docblock).
        $this->authorize('update', $destination);

        $this->pages->moveToNotebook($page, $destination);

        return response()->json([
            'type'     => 'success',
            'message'  => 'Página movida.',
            'redirect' => $this->pages->editUrl($page->fresh()),
        ]);
    }

    public function media(UploadDocumentationMediaRequest $request, Notebook $notebook, DocumentationPage $page): JsonResponse
    {
        return $this->storeDocumentationMedia($request, $page);
    }

    /**
     * Reveals ONE protected value of this page (`{% secret %}`), for one reader,
     * once — see App\Actions\Documentation\RevealPageSecret for the rules and
     * App\Support\Documentation\SecretText for what an ordinal is.
     *
     * Open to every signed-in account on purpose, viewers included: what gates
     * a value is the caderno's secret code, not a role. A role gate here would
     * make the code pointless for exactly the people it is meant for — someone
     * who was told a value and needs to read it once.
     */
    public function revealSecret(
        RevealPageSecretRequest $request,
        Notebook $notebook,
        DocumentationPage $page,
        int $index,
        RevealPageSecret $reveal,
    ): JsonResponse {
        return response()->json([
            'value' => $reveal->handle(
                $notebook,
                $page,
                $index,
                $request->validated()['code'] ?? null,
                $request->user(),
                'u' . $request->user()->id,
            ),
        ]);
    }

    /**
     * Everything inside this caderno a link can point AT: its pages, and the
     * H1–H3 headings of each one. Backs the editor's link picker
     * (`docs-tools/link.js`), which writes `[texto](page:{slug}#anchor)`.
     *
     * Scoped to ONE caderno on purpose. A link is resolved at render time
     * against whichever caderno the reader is in (App\Support\Documentation\PageLinks),
     * and the magic-link reader can only answer for the caderno its token
     * grants — so offering a page from somewhere else would be offering a link
     * that works while you edit and dies the moment the caderno is shared.
     *
     * `update`, not `view`: the picker is an editing affordance, and a reader
     * who cannot edit never gets an editor to open it from.
     */
    public function linkTargets(Notebook $notebook, DocumentationSearchService $search): JsonResponse
    {
        $this->authorize('update', $notebook);

        return response()->json(['pages' => $search->linkTargets($notebook)]);
    }

    /**
     * The pages a person may hand to the Documentation Assistant as context,
     * grouped by caderno with the current one first.
     *
     * Deliberately NOT limited to this caderno, unlike `linkTargets()` above,
     * and the difference is what each thing is for: a LINK has to resolve for
     * whoever reads the page later, while context is read once, now, by the
     * model — and the page most worth showing it is regularly in another
     * caderno (the system on the other end of the integration being
     * documented). The `{notebook}` in the URL is what authorizes the request
     * and which group leads the list, nothing more.
     *
     * Only pages that HAVE content: an empty page is a heading with nothing
     * under it, and offering it as context offers a title.
     */
    public function contextPages(Notebook $notebook): JsonResponse
    {
        $this->authorize('update', $notebook);

        $groups = Notebook::query()
            ->with(['documentedPages' => fn ($query) => $query->select('id', 'notebook_id', 'title', 'position', 'parent_id')])
            ->orderBy('name')
            ->get()
            ->map(fn (Notebook $book): array => [
                'notebook' => $book->name,
                'current'  => $book->is($notebook),
                'pages'    => $book->documentedPages
                    ->map(fn (DocumentationPage $page): array => ['id' => $page->id, 'title' => $page->title])
                    ->all(),
            ])
            ->reject(fn (array $group): bool => $group['pages'] === [])
            // The caderno being written leads: it is where most of what a person
            // reaches for lives, and a search box below it covers the rest.
            ->sortByDesc('current')
            ->values();

        return response()->json(['groups' => $groups]);
    }

    /* --- Documentation Assistant (chat that helps write the page's content) --- */

    public function chatPanel(Notebook $notebook, DocumentationPage $page): JsonResponse
    {
        $page->setRelation('notebook', $notebook);

        return $this->chatPanelResponse(
            $notebook,
            $page,
            route('notebooks.chat.messages.store', [$notebook, $page]),
        );
    }

    public function sendMessage(StoreDocumentationChatMessageRequest $request, Notebook $notebook, DocumentationPage $page): JsonResponse
    {
        $page->setRelation('notebook', $notebook);

        return $this->sendChatMessage($request, $notebook, $page);
    }

    /** Polling — the chat carries its own target, so no {page} is needed. */
    public function chatStatus(Notebook $notebook, DocumentationChat $chat): JsonResponse
    {
        return $this->chatStatusResponse($notebook, $chat);
    }

    /** Marks a message's draft as applied. */
    public function applyChatMessage(Notebook $notebook, DocumentationChatMessage $message): JsonResponse
    {
        return $this->applyChatMessageResponse($notebook, $message);
    }

    /**
     * This page's direct sub-pages, as navigation cards.
     *
     * `children()->get()`, never the `children` property: `edit()` reaches here
     * with a page from a single-row route binding, where strict mode does NOT
     * arm its guard (§ Strict mode) — so a lazy load would work silently in dev
     * and quietly cost a query per render forever.
     *
     * @return array<int, array{title: string, url: string, hasChildren: bool, hasContent: bool}>
     */
    private function childPages(Notebook $notebook, DocumentationPage $page): array
    {
        return $page->children()->get()->map(fn (DocumentationPage $child) => [
            'title'       => $child->title,
            'url'         => route('notebooks.pages.edit', [$notebook, $child]),
            'hasChildren' => $child->children()->exists(),
            'hasContent'  => trim((string) $child->documentation) !== '',
        ])->all();
    }
}
