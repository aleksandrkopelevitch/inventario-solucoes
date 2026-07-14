<?php

namespace App\Http\Controllers;

use App\Http\Controllers\Concerns\EditsDocumentation;
use App\Http\Controllers\Concerns\NavigatesSolutionDocs;
use App\Http\Requests\MoveDocumentationPageRequest;
use App\Http\Requests\SaveDocumentationPageTitleRequest;
use App\Http\Requests\SaveDocumentationRequest;
use App\Http\Requests\UploadDocumentationMediaRequest;
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
 * Documentação rica por Solução — árvore de 1..N páginas (editor de blocos
 * Editor.js por página, formato Markdown + notação GitBook em
 * `documentation_pages.documentation`), consolidada numa única tela junto
 * com a doc de cada Integration em que a solução participa (ver
 * NavigatesSolutionDocs — mesma sidebar mostrada por
 * IntegrationDocumentationController). Thin — delega ao trait
 * EditsDocumentation (por página) e ao DocumentationPageService (regras da
 * árvore: criar/renomear/mover/apagar).
 */
class SolutionDocumentationController extends Controller
{
    use EditsDocumentation, NavigatesSolutionDocs;

    public function __construct(private readonly DocumentationPageService $pages) {}

    /** Índice: resolve (ou cria) a 1ª página e abre o editor nela. */
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
        // Evita lazy load de $page->container dentro de DocumentationPagePolicy
        // (strict mode) — já temos a Solution em mãos via o binding da rota.
        $page->setRelation('container', $solution);

        return $this->documentationView($page, [
            'save'   => route('solutions.docs.update', [$solution, $page]),
            'upload' => route('solutions.docs.media', [$solution, $page]),
            'back'   => route('solutions.show', $solution),
        ], eyebrow: 'Solução · ' . $solution->name, backLabel: $page->title)->with([
            'pagesNav'        => $this->solutionPagesNav($solution, $page),
            'integrationsNav' => $this->solutionIntegrationsNav($solution, null),
            'createPageUrl'   => route('solutions.docs.pages.store', $solution),
            // Compartilhar (link público) só existe na doc da própria Solution
            // — a view genérica (compartilhada com IntegrationDocumentationController)
            // trata como opcional via @isset.
            'coverageSolution' => $solution,
            // O nome da Solution já vira breadcrumb — o topo da tela mostra o
            // título da página atual (ver $backLabel acima), não repete o nome.
            'breadcrumbs' => [
                ['label' => $solution->name, 'url' => route('solutions.show', $solution)],
                ['label' => 'Documentação', 'url' => route('solutions.docs.edit', $solution)],
            ],
        ]);
    }

    public function update(SaveDocumentationRequest $request, Solution $solution, DocumentationPage $page): JsonResponse
    {
        $response = $this->persistDocumentation($request, $page);

        // Atualiza a seção read-only inline no detalhe da solução, se o usuário
        // voltar pra lá (ajax-slot no-op se o id não estiver na página atual).
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

    /** Gera (se ainda não existe) o token do link público e devolve o painel. */
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

    /** Revoga o link público (zera o token — o link antigo para de funcionar). */
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
