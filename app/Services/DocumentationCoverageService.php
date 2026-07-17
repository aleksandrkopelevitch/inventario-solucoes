<?php

namespace App\Services;

use App\Models\DocumentationGroup;
use App\Models\Integration;
use App\Models\Solution;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Collection;

/**
 * Documentation coverage across the inventory, measured by **actual content**.
 * Integration is still single-page (direct `documentation` column); Solution
 * now has a page tree — "documented" when at least one of its
 * `DocumentationPage`s has content (`Solution::documentedPages()`).
 * Feeds the Documentation hub (cross-cutting view of solutions + integrations
 * + standalone groups). Never in controller/Blade; counters via aggregated
 * queries (no N+1 and no loading the `documentation` longText).
 */
class DocumentationCoverageService
{
    /** SQL expression "has documentation" — only Integration still stores the doc in a direct column. */
    private const INTEGRATION_HAS_DOCS = "documentation is not null and documentation <> ''";

    /**
     * Global coverage counters (whole inventory, independent of any filter)
     * for solutions and integrations.
     *
     * @return array{
     *     solutions: array{documented: int, total: int, percent: float},
     *     integrations: array{documented: int, total: int, percent: float},
     * }
     */
    public function counters(): array
    {
        return [
            'solutions'    => $this->countSolutions(),
            'integrations' => $this->countFor(Integration::query()),
        ];
    }

    /**
     * List grouped by solution: each solution (with its doc status) and the
     * integrations it participates in (each with its own). Applies the hub's
     * filters (search by name, item type and documentation status).
     *
     * Structure of each item:
     *   ['solution' => ['name','slug','url','hasDocs','showStatus'],
     *    'integrations' => [['name','slug','url','hasDocs'], ...]]
     *
     * @param  array<string, mixed>  $filters
     * @return Collection<int, array<string, mixed>>
     */
    public function groups(array $filters): Collection
    {
        $search = trim((string) ($filters['search'] ?? ''));
        $type = in_array($filters['type'] ?? null, ['solutions', 'integrations'], true) ? $filters['type'] : 'all';
        $status = in_array($filters['status'] ?? null, ['documented', 'pending'], true) ? $filters['status'] : 'all';

        $solutions = Solution::query()
            ->select('id', 'name', 'slug')
            ->withExists('documentedPages as has_docs')
            ->when($search !== '', fn (Builder $q) => $q->where(fn (Builder $w) => $w
                ->where('name', 'like', "%{$search}%")
                ->orWhereHas('integrations', fn (Builder $i) => $i->where('integrations.name', 'like', "%{$search}%"))))
            ->with(['integrations' => fn ($rel) => $rel
                ->select('integrations.id', 'integrations.name', 'integrations.slug')
                ->selectRaw('(integrations.documentation is not null and integrations.documentation <> \'\') as has_docs')
                ->orderBy('integrations.name')])
            ->orderBy('name')
            ->get();

        return $solutions
            ->map(function (Solution $solution) use ($type, $status) {
                $showStatus = $type !== 'integrations';
                $showIntegrations = $type !== 'solutions';

                $integrations = $showIntegrations
                    ? $solution->integrations
                        ->filter(fn (Integration $i) => $this->matchesStatus((bool) $i->has_docs, $status))
                        ->map(fn (Integration $i) => [
                            'name'    => $i->name,
                            'slug'    => $i->slug,
                            'url'     => route('solutions.integrations.docs.edit', [$solution, $i]),
                            'hasDocs' => (bool) $i->has_docs,
                        ])
                        ->values()
                    : collect();

                return [
                    'solution' => [
                        'name'       => $solution->name,
                        'slug'       => $solution->slug,
                        'url'        => route('solutions.docs.edit', $solution),
                        'showUrl'    => route('solutions.show', $solution),
                        'hasDocs'    => (bool) $solution->has_docs,
                        'showStatus' => $showStatus,
                    ],
                    'integrations' => $integrations,
                    // Keeps the group visible if the solution itself matches
                    // the status filter (when applicable) OR any integration
                    // survived the filter.
                    'keep' => ($showStatus && $this->matchesStatus((bool) $solution->has_docs, $status))
                        || $integrations->isNotEmpty(),
                ];
            })
            ->filter(fn (array $group) => $group['keep'])
            ->map(fn (array $group) => collect($group)->except('keep')->all())
            ->values();
    }

    /**
     * Standalone groups ("Nestings") — simple listing for the hub, without a
     * coverage % (they don't have a natural "total" like Solutions/Integrations).
     *
     * @return Collection<int, array{name: string, slug: string, url: string, pageCount: int}>
     */
    public function groupsList(): Collection
    {
        return DocumentationGroup::query()
            ->withCount('pages')
            ->orderBy('name')
            ->get()
            ->map(fn (DocumentationGroup $group) => [
                'name'      => $group->name,
                'slug'      => $group->slug,
                'url'       => route('documentation.groups.show', $group),
                'pageCount' => $group->pages_count,
            ]);
    }

    /** @return array{documented: int, total: int, percent: float} */
    private function countSolutions(): array
    {
        $total = Solution::query()->count();
        $documented = Solution::query()->whereHas('documentedPages')->count();

        return $this->percentages($documented, $total);
    }

    /** @return array{documented: int, total: int, percent: float} */
    private function countFor(Builder $query): array
    {
        $row = $query
            ->selectRaw('count(*) as total, sum(case when ' . self::INTEGRATION_HAS_DOCS . ' then 1 else 0 end) as documented')
            ->first();

        return $this->percentages((int) $row->documented, (int) $row->total);
    }

    /** @return array{documented: int, total: int, percent: float} */
    private function percentages(int $documented, int $total): array
    {
        return [
            'documented' => $documented,
            'total'      => $total,
            'percent'    => $total > 0 ? round($documented / $total * 100) : 0.0,
        ];
    }

    private function matchesStatus(bool $hasDocs, string $status): bool
    {
        return match ($status) {
            'documented' => $hasDocs,
            'pending'    => ! $hasDocs,
            default      => true,
        };
    }
}
