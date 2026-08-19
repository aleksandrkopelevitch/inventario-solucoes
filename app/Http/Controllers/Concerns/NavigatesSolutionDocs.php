<?php

namespace App\Http\Controllers\Concerns;

use App\Models\DocumentationPage;
use App\Models\Integration;
use App\Models\Solution;
use App\Services\DocumentationPageService;

/**
 * Consolidates, into a single navigation tree, a Solution's pages
 * (`DocumentationPage`, manageable: create/rename/move/delete) and the
 * documentation of each Integration it participates in (single-page,
 * link-only — not manageable from here). Used both by
 * `SolutionDocumentationController` (navigating the Solution's own pages)
 * and `IntegrationDocumentationController` (navigating an integration's
 * docs), so both show the exact same sidebar — "one page per solution", as
 * requested.
 */
trait NavigatesSolutionDocs
{
    /** @return array<int, array<string, mixed>> */
    private function solutionPagesNav(Solution $solution, ?DocumentationPage $active): array
    {
        // One list for the whole rail, not one per row — see
        // DocumentationPageService::destinationsFor().
        $destinations = app(DocumentationPageService::class)->destinationsFor($solution);

        return $solution->pages()->get()->map(fn (DocumentationPage $page) => [
            'title'        => $page->title,
            'editUrl'      => route('solutions.docs.page.edit', [$solution, $page]),
            'renameUrl'    => route('solutions.docs.pages.rename', [$solution, $page]),
            'destroyUrl'   => route('solutions.docs.pages.destroy', [$solution, $page]),
            'moveUrl'      => route('solutions.docs.pages.move', [$solution, $page]),
            'containerUrl' => route('solutions.docs.pages.container', [$solution, $page]),
            'destinations' => $destinations,
            'active'       => $active?->is($page) ?? false,
            'hasContent'   => trim((string) $page->documentation) !== '',
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
