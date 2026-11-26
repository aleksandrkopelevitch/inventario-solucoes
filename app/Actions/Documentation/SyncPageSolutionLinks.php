<?php

namespace App\Actions\Documentation;

use App\Models\DocumentationPage;
use App\Models\UmlSolutionLink;
use App\Support\Documentation\UmlSolutionBindings;

/**
 * Rewrites one page's rows in `uml_solution_links` from its current body.
 *
 * Called by `DocumentationPage::booted()` whenever the body changes, so EVERY
 * writer goes through it — the editor, the MCP, the GitBook import, the CATI
 * promotion — inside that writer's own transaction. Synchronous on purpose: a
 * line scan, a JSON parse per diagram and one query on `solutions.slug`; a
 * queued job would let the solution page contradict the page just saved.
 *
 * Also called for the pages that name a solution created AFTER them
 * (`forPagesNaming()`): their binding derived nothing until the slug existed.
 */
class SyncPageSolutionLinks
{
    public function __construct(private readonly UmlSolutionBindings $bindings) {}

    public function handle(DocumentationPage $page): void
    {
        UmlSolutionLink::query()->where('documentation_page_id', $page->getKey())->delete();

        $rows = array_map(
            fn (array $row) => [...$row, 'documentation_page_id' => $page->getKey()],
            $this->bindings->links($page->documentation),
        );

        foreach (array_chunk($rows, 200) as $chunk) {
            UmlSolutionLink::query()->insert($chunk);
        }
    }

    /**
     * Re-syncs the pages whose body quotes `"<slug>"`. An exact match, not a
     * search (a slug is lowercase ASCII, so there is nothing to fold), and a
     * cheap one: in practice no page, rarely one.
     */
    public function forPagesNaming(string $slug): void
    {
        DocumentationPage::query()
            ->where('documentation', 'like', '%"' . $slug . '"%')
            ->select(['id', 'documentation'])
            ->lazyById()
            ->each(fn (DocumentationPage $page) => $this->handle($page));
    }
}
