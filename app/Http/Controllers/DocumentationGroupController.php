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
 * CRUD for the Group ("Nesting") itself — the tree of pages inside it is
 * managed by DocumentationGroupPageController. A Group does not belong to
 * any Solution (it stays outside the solution/integration coverage hub).
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

    /**
     * Index: resolves (or creates) the 1st page and opens the editor on it.
     *
     * Same reasoning as `SolutionDocumentationController::index()`: creating
     * that first page is a WRITE, gated on `DocumentationGroupPolicy::update`
     * (admin-only). The hub links here for groups with zero pages too, so a
     * viewer following that link used to silently create a page just by
     * browsing — they go back to the hub instead, which lists this group with
     * its (zero) page count.
     */
    public function show(DocumentationGroup $group): RedirectResponse
    {
        $page = $this->pages->firstPage($group);

        if (! $page) {
            if (auth()->user()->cannot('update', $group)) {
                return redirect()->route('documentation.index');
            }

            $page = $this->pages->create($group, 'Página inicial');
        }

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
