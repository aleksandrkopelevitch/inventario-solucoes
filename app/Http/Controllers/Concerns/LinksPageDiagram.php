<?php

namespace App\Http\Controllers\Concerns;

use App\Models\Diagram;
use App\Models\DocumentationPage;
use App\Services\DocumentationPageService;
use Illuminate\Http\JsonResponse;

/**
 * Points a page at a diagram, or clears the link.
 *
 * Written through the relation, never `update()`: `diagram_id` is not in
 * `DocumentationPage::$fillable` (§ Security — same treatment as `parent_id`
 * and `notebook_id`).
 *
 * Answers with a `redirect` to the page's own editor rather than an updatable
 * slot, and that is deliberate. Linking changes what the screen IS: a page
 * with a diagram grows a Documentação/Diagrama tab pair and mounts the canvas,
 * and the canvas is a single page-level mount (`chain-viz.js` reads its whole
 * payload off one hidden row on load). Swapping a slot would leave the tab bar
 * describing a canvas that isn't there — the same reasoning
 * `moveToNotebook()` gives for redirecting instead of patching.
 */
trait LinksPageDiagram
{
    protected function linkPageDiagram(DocumentationPage $page, ?int $diagramId): JsonResponse
    {
        $diagram = $diagramId !== null ? Diagram::find($diagramId) : null;

        $page->diagram()->associate($diagram);
        $page->save();

        return response()->json([
            'type'     => 'success',
            'message'  => $diagram ? 'Diagrama vinculado.' : 'Diagrama desvinculado.',
            'redirect' => app(DocumentationPageService::class)->editUrl($page),
        ]);
    }

    /**
     * Every diagram, as picker options. The picker is a plain select: the
     * blank option is the unlink, and the list is small enough (tens) that a
     * searchable panel would be chrome without a purpose. Revisit if it grows
     * past a screenful.
     *
     * @return array<int, array{value: int, label: string}>
     */
    protected function diagramOptions(): array
    {
        return Diagram::orderBy('name')->get(['id', 'name'])
            ->map(fn (Diagram $diagram) => ['value' => $diagram->id, 'label' => $diagram->name])
            ->all();
    }
}
