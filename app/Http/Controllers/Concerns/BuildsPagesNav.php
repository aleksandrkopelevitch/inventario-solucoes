<?php

namespace App\Http\Controllers\Concerns;

use App\Models\DocumentationPage;
use App\Models\Notebook;
use App\Services\DocumentationPageService;
use App\View\Components\Documentation\PagesNav;

/**
 * The pages rail's rows.
 *
 * Shared by the two controllers that can answer with the rail: the page
 * controller (which renders and reorders it) and the notebook controller —
 * renaming a caderno from the rail's own header has to hand the rail back with
 * the new name on it, and that endpoint is `notebooks.update`.
 */
trait BuildsPagesNav
{
    /**
     * Rows in reading order, each carrying its `depth` (0..MAX_DEPTH-1), which
     * structural gestures it can offer, and whether its branch loads open. All
     * three come straight off `DocumentationPageService::navRows()`, which
     * wraps the same `tree()` that validates an incoming move: the rail and the
     * endpoint read one source.
     *
     * @return array<int, array<string, mixed>>
     */
    protected function pagesNav(Notebook $notebook, ?DocumentationPage $active): array
    {
        $pages = app(DocumentationPageService::class);

        // One list for the whole rail, not one per row — see
        // DocumentationPageService::destinationsFor().
        $destinations = $pages->destinationsFor($notebook);

        return $pages->navRows($notebook, $active)->map(fn (array $row) => [
            'id'           => $row['page']->id,
            'title'        => $row['page']->title,
            'depth'        => $row['depth'],
            'hasChildren'  => $row['hasChildren'],
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
        ])->all();
    }

    /**
     * The rail as an updatable slot, with everything its header needs.
     *
     * @return array{id: string, content: string}
     */
    protected function pagesNavSlot(Notebook $notebook, ?DocumentationPage $active): array
    {
        return PagesNav::slot(
            $this->pagesNav($notebook, $active),
            route('notebooks.pages.store', $notebook),
            $notebook->name,
            // The header renames the caderno in place. `?page=` rides along so
            // the endpoint can hand this same rail back with the new name —
            // same trick the catalog's filters use to survive a side-panel save.
            route('notebooks.update', ['notebook' => $notebook, 'page' => $active?->slug]),
            auth()->user()?->can('update', $notebook) ?? false,
        );
    }
}
