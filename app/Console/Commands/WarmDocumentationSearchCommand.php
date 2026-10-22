<?php

namespace App\Console\Commands;

use App\Http\Controllers\KnowledgeBaseController;
use App\Models\Notebook;
use App\Services\DocumentationSearchService;
use Illuminate\Console\Command;

/**
 * `documentation:search-warm` — builds every documentation search index a
 * reader can reach, so no reader pays for one (see
 * .claude/rules/docs-search-index.md).
 *
 * A cold index is built on the first search that needs it. For one caderno
 * that costs up to ~6 s (the largest measured), hidden behind the palette's
 * placeholder. For "Todos os cadernos" on `/docs` it is every cold caderno, and
 * past a 3 s budget per request the palette answers "Indexando N cadernos…" and
 * asks again until it is done: ~70 s over the dev corpus, about as long as the
 * palette keeps asking (20 retries). Indexes also expire seven days after they
 * were WRITTEN, however often they are read, so the cold start comes back.
 *
 * So this runs on every deploy (after `up`, see Envoy.blade.php) and daily
 * (routes/console.php). It warms, in this order:
 *
 * - every caderno on `/docs` — the palette's own list, home left out — plus
 *   every caderno shared by magic link, whose palette reads the same per-caderno
 *   index;
 * - then the `/docs` global index, which by then only has to compact them.
 *
 * What is already warm is not rebuilt, only given another full TTL (`touch()`),
 * so a run with nothing to build takes seconds. A caderno whose content changed
 * has a new key and is built again.
 */
class WarmDocumentationSearchCommand extends Command
{
    protected $signature = 'documentation:search-warm';

    protected $description = 'Build the documentation search indexes ahead of readers: every caderno on /docs or behind a magic link, then the /docs global index';

    public function handle(DocumentationSearchService $search): int
    {
        $started = hrtime(true);

        // THE list the palette searches: the global index is keyed by it, so
        // any other list would warm an index nobody reads.
        $searchable = KnowledgeBaseController::searchableNotebooks();

        $sharedOnly = Notebook::query()
            ->whereNotNull('public_token')
            ->whereNotIn('id', $searchable->modelKeys())
            ->orderBy('name')
            ->get();

        $built = 0;

        foreach ($searchable->concat($sharedOnly) as $notebook) {
            $at = hrtime(true);
            $wasBuilt = $search->warm($notebook);
            $built += (int) $wasBuilt;

            $this->components->twoColumnDetail(
                $notebook->name,
                $wasBuilt ? sprintf('<fg=yellow>built</> in %.1f s', $this->seconds($at)) : '<fg=gray>already warm</>',
            );
        }

        $at = hrtime(true);
        $global = $search->warmAll($searchable);

        $this->components->twoColumnDetail(
            '<options=bold>Todos os cadernos (/docs)</>',
            $global['built'] ? sprintf('<fg=yellow>built</> in %.1f s', $this->seconds($at)) : '<fg=gray>already warm</>',
        );

        $this->newLine();
        $this->components->info(sprintf(
            'Warmed %d caderno(s) (%d on /docs, %d by magic link only): %d built, %d already warm, in %.1f s.',
            $searchable->count() + $sharedOnly->count(),
            $searchable->count(),
            $sharedOnly->count(),
            $built,
            $searchable->count() + $sharedOnly->count() - $built,
            $this->seconds($started),
        ));

        // Only when a caderno changed while this ran, past the global index's
        // build budget. Not a failure: the next "Todos" search finishes it.
        if ($global['pending'] !== []) {
            $this->components->warn(
                'The global index is incomplete — changed while warming: ' . implode(', ', $global['pending'])
                . '. The next search over every caderno will finish it.'
            );
        }

        return self::SUCCESS;
    }

    private function seconds(int $since): float
    {
        return (hrtime(true) - $since) / 1e9;
    }
}
