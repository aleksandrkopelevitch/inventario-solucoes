<?php

namespace App\Http\Controllers\Concerns;

use App\Models\DocumentationPage;
use App\Models\Solution;
use App\Services\DocumentationPageService;

/**
 * The pages rail for a Solution's documentation.
 *
 * It used to consolidate two different things into one tree — the solution's
 * own pages plus each participating integration's single-page documentation —
 * because there were two kinds of documentation and one screen had to show
 * both. There is one kind now, so this builds one list; what a page has BESIDES
 * text (a linked `Diagram`) travels as a flag on its row.
 */
trait NavigatesSolutionDocs
{
    /**
     * Rows in reading order, each carrying its `depth` (0..MAX_DEPTH-1) and
     * which structural gestures it can offer. Both come straight
     * off `DocumentationPageService::tree()`, which is also what validates an
     * incoming move: the rail and the endpoint read one source.
     *
     * @return array<int, array<string, mixed>>
     */
    private function solutionPagesNav(Solution $solution, ?DocumentationPage $active): array
    {
        $service = app(DocumentationPageService::class);

        // One list for the whole rail, not one per row — see
        // DocumentationPageService::destinationsFor().
        $destinations = $service->destinationsFor($solution);

        return $service->tree($solution)->map(fn (array $row) => [
            'id'           => $row['page']->id,
            'title'        => $row['page']->title,
            'depth'        => $row['depth'],
            'hasChildren'  => $row['hasChildren'],
            'canNest'      => $row['canNest'],
            'canPromote'   => $row['canPromote'],
            'canAddChild'  => $row['canAddChild'],
            'editUrl'      => route('solutions.docs.page.edit', [$solution, $row['page']]),
            'renameUrl'    => route('solutions.docs.pages.rename', [$solution, $row['page']]),
            'destroyUrl'   => route('solutions.docs.pages.destroy', [$solution, $row['page']]),
            'moveUrl'      => route('solutions.docs.pages.move', [$solution, $row['page']]),
            'containerUrl' => route('solutions.docs.pages.container', [$solution, $row['page']]),
            'destinations' => $destinations,
            'active'       => $active?->is($row['page']) ?? false,
            'hasContent'   => trim((string) $row['page']->documentation) !== '',
            // The FK itself, never the loaded relation: `tree()` hydrates every
            // page of the container in one multi-row query, which is exactly
            // where strict mode arms — reading `$page->diagram` here would be a
            // LazyLoadingViolationException on any container with two pages.
            'hasDiagram' => $row['page']->diagram_id !== null,
        ])->all();
    }
}
