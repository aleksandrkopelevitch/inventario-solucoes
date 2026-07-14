<?php

namespace App\Http\Controllers;

use App\Http\Requests\SaveDocumentationGroupRequest;
use App\Models\DocumentationGroup;
use App\Services\DocumentationPageService;
use App\View\Components\Documentation\GroupsList;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Support\Str;

/**
 * CRUD do Grupo ("Aninhamento") em si — a árvore de páginas dentro dele é
 * gerenciada por DocumentationGroupPageController. Um Grupo não pertence a
 * nenhuma Solução (fica fora do hub de cobertura solução/integração).
 */
class DocumentationGroupController extends Controller
{
    public function __construct(private readonly DocumentationPageService $pages) {}

    public function store(SaveDocumentationGroupRequest $request): JsonResponse
    {
        $name = $request->validated()['name'];

        $group = DocumentationGroup::create([
            'name' => $name,
            'slug' => $this->uniqueSlug($name),
        ]);

        return response()->json([
            'type'     => 'success',
            'message'  => 'Grupo criado.',
            'redirect' => route('documentation.groups.show', $group),
        ]);
    }

    /** Índice: resolve (ou cria) a 1ª página e abre o editor nela. */
    public function show(DocumentationGroup $group): RedirectResponse
    {
        $page = $group->pages()->first() ?? $this->pages->create($group, 'Página inicial');

        return redirect()->route('documentation.groups.pages.edit', [$group, $page]);
    }

    public function update(SaveDocumentationGroupRequest $request, DocumentationGroup $group): JsonResponse
    {
        $group->update(['name' => $request->validated()['name']]);

        return response()->json([
            'type'           => 'success',
            'message'        => 'Grupo renomeado.',
            'updatableSlots' => [GroupsList::slot()],
        ]);
    }

    public function destroy(DocumentationGroup $group): JsonResponse
    {
        $this->authorize('delete', $group);

        $group->delete();

        return response()->json([
            'type'     => 'success',
            'message'  => 'Grupo excluído.',
            'redirect' => route('documentation.index'),
        ]);
    }

    private function uniqueSlug(string $name): string
    {
        $base = Str::slug($name) ?: 'grupo';
        $slug = $base;
        $suffix = 1;

        while (DocumentationGroup::where('slug', $slug)->exists()) {
            $slug = $base . '-' . (++$suffix);
        }

        return $slug;
    }
}
