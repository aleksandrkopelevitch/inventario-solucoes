<?php

namespace App\Http\Controllers;

use App\Http\Controllers\Concerns\AssistsDocumentation;
use App\Http\Controllers\Concerns\EditsDocumentation;
use App\Http\Controllers\Concerns\LinksPageDiagram;
use App\Http\Requests\LinkPageDiagramRequest;
use App\Http\Requests\MoveDocumentationPageRequest;
use App\Http\Requests\MoveDocumentationPageToNotebookRequest;
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
use App\View\Components\Documentation\PagesNav;
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
    use AssistsDocumentation, EditsDocumentation, LinksPageDiagram;

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
            notebookLabel: $notebook->name,
            notebookUrl: route('notebooks.show', $notebook),
        )->with([
            'pagesNav'      => $this->pagesNav($notebook, $page),
            'createPageUrl' => route('notebooks.pages.store', $notebook),
            'chatPanelUrl'  => route('notebooks.chat.panel', [$notebook, $page]),
            'chatResume'    => $this->chatResumeFor($notebook, $page),
            // The page's drawing, if it has one, plus the picker that links or
            // unlinks it — see LinksPageDiagram.
            'diagram'        => $page->diagram,
            'diagramOptions' => $this->diagramOptions(),
            'diagramAction'  => route('notebooks.pages.diagram', [$notebook, $page]),
            // Navigation cards for this page's own sub-pages — see
            // x-documentation.child-pages for why an imported corpus needs them.
            'childPages' => $this->childPages($notebook, $page),
            // Sharing and the linked solutions both belong to the CADERNO, not
            // to this page — they sit in the toolbar and are the same for every
            // page of the tree.
            'notebook'    => $notebook,
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

    public function rename(SaveDocumentationPageTitleRequest $request, Notebook $notebook, DocumentationPage $page): JsonResponse
    {
        $this->pages->rename($page, $request->validated()['title']);

        return response()->json([
            'type'     => 'success',
            'message'  => 'Página renomeada.',
            'redirect' => route('notebooks.pages.edit', [$notebook, $page]),
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
            'updatableSlots' => [PagesNav::slot(
                $this->pagesNav($notebook, $page->fresh()),
                route('notebooks.pages.store', $notebook),
                $notebook->name,
                route('notebooks.show', $notebook),
            )],
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

    /** Points this page at a diagram, or clears the link. */
    public function diagram(LinkPageDiagramRequest $request, Notebook $notebook, DocumentationPage $page): JsonResponse
    {
        $page->setRelation('notebook', $notebook);

        return $this->linkPageDiagram($page, $request->validated()['diagram_id']);
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

    /**
     * Rows in reading order, each carrying its `depth` (0..MAX_DEPTH-1), which
     * structural gestures it can offer, and whether its branch loads open. All
     * three come straight off `DocumentationPageService::navRows()`, which
     * wraps the same `tree()` that validates an incoming move: the rail and the
     * endpoint read one source.
     *
     * @return array<int, array<string, mixed>>
     */
    private function pagesNav(Notebook $notebook, ?DocumentationPage $active): array
    {
        // One list for the whole rail, not one per row — see
        // DocumentationPageService::destinationsFor().
        $destinations = $this->pages->destinationsFor($notebook);

        return $this->pages->navRows($notebook, $active)->map(fn (array $row) => [
            'id'          => $row['page']->id,
            'title'       => $row['page']->title,
            'depth'       => $row['depth'],
            'hasChildren' => $row['hasChildren'],
            // Tree state for the collapsible rail — see navRows().
            'parentId'     => $row['parentId'],
            'expanded'     => $row['expanded'],
            'visible'      => $row['visible'],
            'canNest'      => $row['canNest'],
            'canPromote'   => $row['canPromote'],
            'canAddChild'  => $row['canAddChild'],
            'editUrl'      => route('notebooks.pages.edit', [$notebook, $row['page']]),
            'renameUrl'    => route('notebooks.pages.rename', [$notebook, $row['page']]),
            'destroyUrl'   => route('notebooks.pages.destroy', [$notebook, $row['page']]),
            'moveUrl'      => route('notebooks.pages.move', [$notebook, $row['page']]),
            'notebookUrl'  => route('notebooks.pages.notebook', [$notebook, $row['page']]),
            'destinations' => $destinations,
            'active'       => $active?->is($row['page']) ?? false,
            'hasContent'   => trim((string) $row['page']->documentation) !== '',
            // The FK itself, never the loaded relation: `tree()` hydrates every
            // page of the notebook in one multi-row query, which is exactly
            // where strict mode arms — reading `$page->diagram` here would be a
            // LazyLoadingViolationException on any caderno with two pages.
            'hasDiagram' => $row['page']->diagram_id !== null,
        ])->all();
    }
}
