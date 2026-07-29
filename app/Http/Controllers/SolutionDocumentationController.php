<?php

namespace App\Http\Controllers;

use App\Http\Controllers\Concerns\AssistsDocumentation;
use App\Http\Controllers\Concerns\EditsDocumentation;
use App\Http\Controllers\Concerns\NavigatesSolutionDocs;
use App\Http\Requests\MoveDocumentationPageRequest;
use App\Http\Requests\SaveDocumentationPageTitleRequest;
use App\Http\Requests\SaveDocumentationRequest;
use App\Http\Requests\StoreDocumentationChatMessageRequest;
use App\Http\Requests\UploadDocumentationMediaRequest;
use App\Models\DocumentationChat;
use App\Models\DocumentationChatMessage;
use App\Models\DocumentationPage;
use App\Models\Solution;
use App\Services\DocumentationPageService;
use App\View\Components\Documentation\PagesNav;
use App\View\Components\Solutions\Documentation;
use App\View\Components\Solutions\SharePanel;
use Illuminate\Contracts\View\View;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Support\Str;

/**
 * Rich documentation per Solution — a tree of 1..N pages (Editor.js block
 * editor per page, Markdown format + GitBook notation in
 * `documentation_pages.documentation`), consolidated into a single screen
 * alongside the docs of each Integration the solution participates in (see
 * NavigatesSolutionDocs — same sidebar shown by
 * IntegrationDocumentationController). Thin — delegates to the
 * EditsDocumentation trait (per page) and to DocumentationPageService (tree
 * rules: create/rename/move/delete).
 */
class SolutionDocumentationController extends Controller
{
    use AssistsDocumentation, EditsDocumentation, NavigatesSolutionDocs;

    public function __construct(private readonly DocumentationPageService $pages) {}

    /** Index: resolves (or creates) the 1st page and opens the editor on it. */
    public function index(Solution $solution): RedirectResponse
    {
        $page = $solution->pages()->first() ?? $this->pages->create($solution, 'Visão geral');

        return redirect()->route('solutions.docs.page.edit', [$solution, $page]);
    }

    public function store(SaveDocumentationPageTitleRequest $request, Solution $solution): JsonResponse
    {
        $page = $this->pages->create($solution, $request->validated()['title']);

        return response()->json([
            'type'     => 'success',
            'message'  => 'Página criada.',
            'redirect' => route('solutions.docs.page.edit', [$solution, $page]),
        ]);
    }

    public function edit(Solution $solution, DocumentationPage $page): View
    {
        // Avoids lazy loading $page->container inside DocumentationPagePolicy
        // (strict mode) — we already have the Solution in hand via the route binding.
        $page->setRelation('container', $solution);

        return $this->documentationView($page, [
            'save'   => route('solutions.docs.update', [$solution, $page]),
            'upload' => route('solutions.docs.media', [$solution, $page]),
            'back'   => route('solutions.show', $solution),
        ], eyebrow: 'Solução · ' . $solution->name, backLabel: $page->title)->with([
            'pagesNav'        => $this->solutionPagesNav($solution, $page),
            'integrationsNav' => $this->solutionIntegrationsNav($solution, null),
            'createPageUrl'   => route('solutions.docs.pages.store', $solution),
            'chatPanelUrl'    => route('solutions.docs.chat.panel', [$solution, $page]),
            'chatResume'      => $this->chatResumeFor($solution, $page),
            // Share (public link) only exists on the Solution's own docs — the
            // generic view (shared with IntegrationDocumentationController)
            // treats it as optional via @isset.
            'coverageSolution' => $solution,
            // The Solution's name already becomes a breadcrumb — the top of
            // the screen shows the current page's title (see $backLabel
            // above), it doesn't repeat the name.
            'breadcrumbs' => [
                ['label' => $solution->name, 'url' => route('solutions.show', $solution)],
                ['label' => 'Documentação', 'url' => route('solutions.docs.edit', $solution)],
            ],
        ]);
    }

    public function update(SaveDocumentationRequest $request, Solution $solution, DocumentationPage $page): JsonResponse
    {
        $response = $this->persistDocumentation($request, $page);

        // Updates the inline read-only section on the solution's detail page,
        // in case the user goes back there (ajax-slot no-ops if the id isn't
        // on the current page).
        return $response->setData($response->getData(true) + [
            'updatableSlots' => [Documentation::slot($solution->fresh())],
        ]);
    }

    public function rename(SaveDocumentationPageTitleRequest $request, Solution $solution, DocumentationPage $page): JsonResponse
    {
        $this->pages->rename($page, $request->validated()['title']);

        return response()->json([
            'type'     => 'success',
            'message'  => 'Página renomeada.',
            'redirect' => route('solutions.docs.page.edit', [$solution, $page]),
        ]);
    }

    public function destroy(Solution $solution, DocumentationPage $page): JsonResponse
    {
        $this->authorize('update', $solution);

        $next = $this->pages->delete($page);

        return response()->json([
            'type'     => 'success',
            'message'  => 'Página excluída.',
            'redirect' => $next
                ? route('solutions.docs.page.edit', [$solution, $next])
                : route('solutions.docs.edit', $solution),
        ]);
    }

    public function move(MoveDocumentationPageRequest $request, Solution $solution, DocumentationPage $page): JsonResponse
    {
        $this->pages->move($page, $request->validated()['direction']);

        return response()->json([
            'type'           => 'success',
            'message'        => 'Ordem atualizada.',
            'updatableSlots' => [PagesNav::slot(
                $this->solutionPagesNav($solution, $page->fresh()),
                $this->solutionIntegrationsNav($solution, null),
                route('solutions.docs.pages.store', $solution),
            )],
        ]);
    }

    public function media(UploadDocumentationMediaRequest $request, Solution $solution, DocumentationPage $page): JsonResponse
    {
        return $this->storeDocumentationMedia($request, $page);
    }

    /* --- Documentation Assistant (chat that helps write the page's content) --- */

    public function chatPanel(Solution $solution, DocumentationPage $page): JsonResponse
    {
        $page->setRelation('container', $solution);

        return $this->chatPanelResponse(
            $solution,
            $page,
            route('solutions.docs.chat.messages.store', [$solution, $page]),
        );
    }

    public function sendMessage(StoreDocumentationChatMessageRequest $request, Solution $solution, DocumentationPage $page): JsonResponse
    {
        $page->setRelation('container', $solution);

        return $this->sendChatMessage($request, $solution, $page);
    }

    /** Polling — serves pages and integrations, the chat carries its own target. */
    public function chatStatus(Solution $solution, DocumentationChat $chat): JsonResponse
    {
        return $this->chatStatusResponse($solution, $chat);
    }

    /** Marks a message's draft as applied — serves pages and integrations. */
    public function applyChatMessage(Solution $solution, DocumentationChatMessage $message): JsonResponse
    {
        return $this->applyChatMessageResponse($solution, $message);
    }

    /** Generates (if it doesn't exist yet) the public link token and returns the panel. */
    public function share(Solution $solution): JsonResponse
    {
        $this->authorize('update', $solution);

        if (! $solution->public_token) {
            $solution->update(['public_token' => Str::random(40)]);
        }

        return response()->json([
            'type'           => 'success',
            'message'        => 'Link público gerado.',
            'updatableSlots' => [SharePanel::slot($solution->fresh())],
        ]);
    }

    /** Revokes the public link (clears the token — the old link stops working). */
    public function unshare(Solution $solution): JsonResponse
    {
        $this->authorize('update', $solution);

        $solution->update(['public_token' => null]);

        return response()->json([
            'type'           => 'success',
            'message'        => 'Acesso público revogado.',
            'updatableSlots' => [SharePanel::slot($solution->fresh())],
        ]);
    }
}
