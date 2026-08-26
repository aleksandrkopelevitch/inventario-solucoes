<?php

namespace App\Services;

use App\Models\DocumentationGroup;
use App\Models\DocumentationPage;
use App\Models\Solution;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Collection;

/**
 * Documentation coverage across the inventory, measured by **actual content**.
 *
 * There is one kind of documentation now — the `DocumentationPage` tree — so
 * this counts one thing at two levels: a Solution is documented when at least
 * one of its pages has content, and a page is documented when it has any. The
 * hub used to have a second, parallel half listing each integration's own
 * `documentation` column; that column and the entity are gone, and drawings
 * have a module of their own (`DiagramCatalogService`) rather than a coverage
 * percentage here.
 *
 * Never called from a controller body or a Blade view; counters are aggregate
 * queries, so the `documentation` longText is never loaded to produce them.
 */
class DocumentationCoverageService
{
    /**
     * Global coverage counters (whole inventory, independent of any filter).
     *
     * @return array{
     *     solutions: array{documented: int, total: int, percent: float},
     *     pages: array{documented: int, total: int, percent: float},
     * }
     */
    public function counters(): array
    {
        return [
            'solutions' => $this->percentages(
                Solution::query()->whereHas('documentedPages')->count(),
                Solution::query()->count(),
            ),
            'pages' => $this->percentages(
                DocumentationPage::query()->whereNotNull('documentation')->where('documentation', '<>', '')->count(),
                DocumentationPage::query()->count(),
            ),
        ];
    }

    /**
     * List grouped by solution: each solution (with its doc status) and its own
     * pages (each with its own, plus whether it carries a diagram). Applies the
     * hub's filters (search by name, documentation status).
     *
     * The pages are listed by TITLE, not by `position`. `position` orders a
     * page among its SIBLINGS, so a flat `orderBy('position')` across every
     * depth is not reading order (§ Documentation page tree) — and a list that
     * is neither alphabetical nor the tree reads as broken. This is a coverage
     * checklist, not the tree; walking `tree()` per solution would also be one
     * query per row on a hundred-solution page.
     *
     * Structure of each item:
     *   ['solution' => ['name','slug','url','showUrl','hasDocs'],
     *    'pages' => [['title','url','hasDocs','hasDiagram'], ...]]
     *
     * @param  array<string, mixed>  $filters
     * @return Collection<int, array<string, mixed>>
     */
    public function groups(array $filters): Collection
    {
        $search = trim((string) ($filters['search'] ?? ''));
        $status = in_array($filters['status'] ?? null, ['documented', 'pending'], true) ? $filters['status'] : 'all';

        $solutions = Solution::query()
            ->select('id', 'name', 'slug')
            ->withExists('documentedPages as has_docs')
            ->when($search !== '', fn (Builder $q) => $q->where(fn (Builder $w) => $w
                ->where('name', 'like', "%{$search}%")
                ->orWhereHas('pages', fn (Builder $p) => $p->where('title', 'like', "%{$search}%"))))
            ->with(['pages' => fn ($rel) => $rel
                ->select('documentation_pages.id', 'documentation_pages.container_type', 'documentation_pages.container_id',
                    'documentation_pages.title', 'documentation_pages.slug', 'documentation_pages.diagram_id')
                // The flag, not the longText: a hub listing every page of every
                // solution would otherwise pull the whole corpus into memory.
                ->selectRaw("(documentation is not null and documentation <> '') as has_docs")
                ->reorder('documentation_pages.title')])
            ->orderBy('name')
            ->get();

        return $solutions
            ->map(function (Solution $solution) use ($status) {
                $pages = $solution->pages
                    ->filter(fn (DocumentationPage $page) => $this->matchesStatus((bool) $page->has_docs, $status))
                    ->map(fn (DocumentationPage $page) => [
                        'title'      => $page->title,
                        'url'        => route('solutions.docs.page.edit', [$solution, $page]),
                        'hasDocs'    => (bool) $page->has_docs,
                        'hasDiagram' => $page->diagram_id !== null,
                    ])
                    ->values();

                return [
                    'solution' => [
                        'name'    => $solution->name,
                        'slug'    => $solution->slug,
                        'url'     => route('solutions.docs.edit', $solution),
                        'showUrl' => route('solutions.show', $solution),
                        'hasDocs' => (bool) $solution->has_docs,
                    ],
                    'pages' => $pages,
                    // Keeps the group visible if the solution itself matches the
                    // status filter OR any of its pages survived it.
                    'keep' => $this->matchesStatus((bool) $solution->has_docs, $status) || $pages->isNotEmpty(),
                ];
            })
            ->filter(fn (array $group) => $group['keep'])
            ->map(fn (array $group) => collect($group)->except('keep')->all())
            ->values();
    }

    /**
     * Standalone groups ("Nestings") — simple listing for the hub, without a
     * coverage % (they don't have a natural "total" the way Solutions do).
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
