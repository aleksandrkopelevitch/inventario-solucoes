<?php

namespace App\Http\Controllers;

use App\Http\Controllers\Concerns\EditsDocumentation;
use App\Http\Requests\MoveDocumentationPageRequest;
use App\Http\Requests\SaveDocumentationPageTitleRequest;
use App\Http\Requests\SaveDocumentationRequest;
use App\Http\Requests\UploadDocumentationMediaRequest;
use App\Models\DocumentationGroup;
use App\Models\DocumentationPage;
use App\Services\DocumentationPageService;
use App\View\Components\Documentation\PagesNav;
use Illuminate\Contracts\View\View;
use Illuminate\Http\JsonResponse;

/**
 * Documentação rica de um Grupo standalone — árvore de 1..N páginas, mesmo
 * mecanismo de SolutionDocumentationController (EditsDocumentation +
 * DocumentationPageService), só que o container é um DocumentationGroup em
 * vez de uma Solution (não tem link público nem "documentações relacionadas").
 */
class DocumentationGroupPageController extends Controller
{
    use EditsDocumentation;

    public function __construct(private readonly DocumentationPageService $pages) {}

    public function store(SaveDocumentationPageTitleRequest $request, DocumentationGroup $group): JsonResponse
    {
        $page = $this->pages->create($group, $request->validated()['title']);

        return response()->json([
            'type'     => 'success',
            'message'  => 'Página criada.',
            'redirect' => route('documentation.groups.pages.edit', [$group, $page]),
        ]);
    }

    public function edit(DocumentationGroup $group, DocumentationPage $page): View
    {
        // Evita lazy load de $page->container dentro de DocumentationPagePolicy
        // (strict mode) — já temos o Group em mãos via o binding da rota.
        $page->setRelation('container', $group);

        return $this->documentationView($page, [
            'save'   => route('documentation.groups.pages.update', [$group, $page]),
            'upload' => route('documentation.groups.pages.media', [$group, $page]),
            'back'   => route('documentation.index'),
        ], eyebrow: 'Grupo · ' . $group->name, backLabel: $page->title)->with([
            'pagesNav'      => $this->navPages($group, $page),
            'createPageUrl' => route('documentation.groups.pages.store', $group),
            'breadcrumbs'   => [
                ['label' => 'Documentação', 'url' => route('documentation.index')],
                ['label' => $group->name, 'url' => route('documentation.groups.show', $group)],
            ],
        ]);
    }

    public function update(SaveDocumentationRequest $request, DocumentationGroup $group, DocumentationPage $page): JsonResponse
    {
        return $this->persistDocumentation($request, $page);
    }

    public function rename(SaveDocumentationPageTitleRequest $request, DocumentationGroup $group, DocumentationPage $page): JsonResponse
    {
        $this->pages->rename($page, $request->validated()['title']);

        return response()->json([
            'type'     => 'success',
            'message'  => 'Página renomeada.',
            'redirect' => route('documentation.groups.pages.edit', [$group, $page]),
        ]);
    }

    public function destroy(DocumentationGroup $group, DocumentationPage $page): JsonResponse
    {
        $this->authorize('update', $group);

        $next = $this->pages->delete($page);

        return response()->json([
            'type'     => 'success',
            'message'  => 'Página excluída.',
            'redirect' => $next
                ? route('documentation.groups.pages.edit', [$group, $next])
                : route('documentation.groups.show', $group),
        ]);
    }

    public function move(MoveDocumentationPageRequest $request, DocumentationGroup $group, DocumentationPage $page): JsonResponse
    {
        $this->pages->move($page, $request->validated()['direction']);

        return response()->json([
            'type'           => 'success',
            'message'        => 'Ordem atualizada.',
            'updatableSlots' => [PagesNav::slot(
                $this->navPages($group, $page->fresh()),
                [],
                route('documentation.groups.pages.store', $group),
            )],
        ]);
    }

    public function media(UploadDocumentationMediaRequest $request, DocumentationGroup $group, DocumentationPage $page): JsonResponse
    {
        return $this->storeDocumentationMedia($request, $page);
    }

    /** @return array<int, array<string, mixed>> */
    private function navPages(DocumentationGroup $group, DocumentationPage $active): array
    {
        return $group->pages()->get()->map(fn (DocumentationPage $page) => [
            'title'      => $page->title,
            'editUrl'    => route('documentation.groups.pages.edit', [$group, $page]),
            'renameUrl'  => route('documentation.groups.pages.rename', [$group, $page]),
            'destroyUrl' => route('documentation.groups.pages.destroy', [$group, $page]),
            'moveUrl'    => route('documentation.groups.pages.move', [$group, $page]),
            'active'     => $active->is($page),
            'hasContent' => trim((string) $page->documentation) !== '',
        ])->all();
    }
}
