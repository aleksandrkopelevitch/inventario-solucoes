<?php

namespace App\Http\Controllers\Concerns;

use App\Models\DocumentationPage;
use App\Models\Integration;
use App\Models\Solution;
use App\Services\DocumentationPageService;

/**
 * Consolidates, into a single navigation tree, a Solution's pages
 * (`DocumentationPage`, manageable: create/rename/move/nest/delete) and the
 * documentation of each Integration it participates in (single-page,
 * link-only — not manageable from here). Used both by
 * `SolutionDocumentationController` (navigating the Solution's own pages)
 * and `IntegrationDocumentationController` (navigating an integration's
 * docs), so both show the exact same sidebar — "one page per solution", as
 * requested.
 */
trait NavigatesSolutionDocs
{
    /**
     * Rows in reading order, each carrying its `depth` (0 or 1 — the tree is
     * two levels) and which nesting arrows it can offer. Both come straight
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
            'editUrl'      => route('solutions.docs.page.edit', [$solution, $row['page']]),
            'renameUrl'    => route('solutions.docs.pages.rename', [$solution, $row['page']]),
            'destroyUrl'   => route('solutions.docs.pages.destroy', [$solution, $row['page']]),
            'moveUrl'      => route('solutions.docs.pages.move', [$solution, $row['page']]),
            'containerUrl' => route('solutions.docs.pages.container', [$solution, $row['page']]),
            'destinations' => $destinations,
            'active'       => $active?->is($row['page']) ?? false,
            'hasContent'   => trim((string) $row['page']->documentation) !== '',
        ])->all();
    }

    /** @return array<int, array<string, mixed>> */
    private function solutionIntegrationsNav(Solution $solution, ?Integration $active): array
    {
        return $solution->integrations()->get()->map(fn (Integration $integration) => [
            'title'      => $integration->name,
            'editUrl'    => route('solutions.integrations.docs.edit', [$solution, $integration]),
            'active'     => $active?->is($integration) ?? false,
            'hasContent' => trim((string) $integration->documentation) !== '',
        ])->all();
    }
}
