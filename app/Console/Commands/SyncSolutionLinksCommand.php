<?php

namespace App\Console\Commands;

use App\Actions\Documentation\SyncPageSolutionLinks;
use App\Models\DocumentationPage;
use App\Models\UmlSolutionLink;
use Illuminate\Console\Command;

/**
 * `documentation:solution-links` — rebuilds `uml_solution_links` from every
 * page's body: the relationships between catalog solutions that the
 * documentation's UML diagrams state.
 *
 * Every save of a page body keeps its rows current on its own
 * (`DocumentationPage::booted()`), so this is not scheduled. It is the
 * backfill for the deploy that introduced the index, and the repair tool
 * afterwards: idempotent, page by page.
 *
 * Run it as www-data in production, like any artisan command there.
 */
class SyncSolutionLinksCommand extends Command
{
    protected $signature = 'documentation:solution-links';

    protected $description = 'Rebuild the solution relationships the documentation UML diagrams state (uml_solution_links)';

    public function handle(SyncPageSolutionLinks $sync): int
    {
        $pages = 0;

        // `%uml%` is a superset of the pages with a `{% uml %}` block — a `%`
        // inside the pattern would be a wildcard, and escaping it differs
        // between Postgres and SQLite. Every page it finds is re-derived.
        DocumentationPage::query()
            ->where('documentation', 'like', '%uml%')
            ->select(['id', 'documentation'])
            ->lazyById()
            ->each(function (DocumentationPage $page) use ($sync, &$pages) {
                $sync->handle($page);
                $pages++;
            });

        // A page whose last UML diagram was removed before the index existed
        // has nothing to rebuild, and no rows either; anything else left over
        // belongs to a page that no longer names a solution.
        UmlSolutionLink::query()
            ->whereNotIn('documentation_page_id', DocumentationPage::query()
                ->where('documentation', 'like', '%uml%')
                ->select('id'))
            ->delete();

        $this->info("Pages read: {$pages}. Links: " . UmlSolutionLink::query()->count() . '.');

        return self::SUCCESS;
    }
}
