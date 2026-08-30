<?php

namespace App\Services;

use App\Models\DocumentationPage;
use App\Models\Notebook;
use App\Models\Solution;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Collection;

/**
 * Documentation coverage across the inventory, measured by **actual content**.
 *
 * There is one kind of documentation — the `DocumentationPage` tree — and one
 * container for it, the `Notebook`. So this counts one thing at two levels: a
 * page is documented when it has any content, and a caderno is documented when
 * at least one of its pages is.
 *
 * The solution counter is the one that changed shape with the container swap,
 * and the change is the point of the whole revamp: a Solution owns no pages,
 * so it is documented when **some notebook linked to it** has content. One
 * caderno therefore covers every solution it describes, instead of the same
 * text having to be written into each of them.
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
     *     notebooks: array{documented: int, total: int, percent: float},
     *     pages: array{documented: int, total: int, percent: float},
     * }
     */
    public function counters(): array
    {
        return [
            'solutions' => $this->percentages(
                Solution::query()->whereHas('notebooks.documentedPages')->count(),
                Solution::query()->count(),
            ),
            'notebooks' => $this->percentages(
                Notebook::query()->whereHas('documentedPages')->count(),
                Notebook::query()->count(),
            ),
            'pages' => $this->percentages(
                DocumentationPage::query()->whereNotNull('documentation')->where('documentation', '<>', '')->count(),
                DocumentationPage::query()->count(),
            ),
        ];
    }

    /**
     * The hub's list, one group per NOTEBOOK: the caderno (with its doc status
     * and the solutions it documents) and its own pages, each with its status
     * and whether it carries a diagram. Applies the hub's filters (search by
     * name, documentation status).
     *
     * It used to group by Solution, which could only ever show a solution's own
     * pages — the 497 pages sitting in standalone groups were listed separately,
     * without coverage, by a second method. One container means one list.
     *
     * The pages are listed by TITLE, not by `position`. `position` orders a
     * page among its SIBLINGS, so a flat `orderBy('position')` across every
     * depth is not reading order (§ Documentation page tree) — and a list that
     * is neither alphabetical nor the tree reads as broken. This is a coverage
     * checklist, not the tree; walking `tree()` per notebook would also be one
     * query per row on a page listing dozens of them.
     *
     * Structure of each item:
     *   ['notebook' => ['name','slug','url','hasDocs','solutions' => [['name','url'], ...]],
     *    'pages' => [['title','url','hasDocs'], ...]]
     *
     * @param  array<string, mixed>  $filters
     * @return Collection<int, array<string, mixed>>
     */
    public function groups(array $filters): Collection
    {
        $search = trim((string) ($filters['search'] ?? ''));
        $status = in_array($filters['status'] ?? null, ['documented', 'pending'], true) ? $filters['status'] : 'all';

        $notebooks = Notebook::query()
            ->select('id', 'name', 'slug')
            ->withExists('documentedPages as has_docs')
            // The linked solutions are part of what a row SAYS (this caderno
            // documents these systems), so a search over them is a search over
            // the row the user is reading.
            ->when($search !== '', fn (Builder $q) => $q->where(fn (Builder $w) => $w
                ->whereFolded('name', $search)
                ->orWhereHas('pages', fn (Builder $p) => $p->whereFolded('title', $search))
                ->orWhereHas('solutions', fn (Builder $sol) => $sol->whereFolded('name', $search))))
            ->with([
                'solutions:id,name,slug',
                'pages' => fn ($rel) => $rel
                    ->select('documentation_pages.id', 'documentation_pages.notebook_id',
                        'documentation_pages.title', 'documentation_pages.slug')
                    // The flag, not the longText: a hub listing every page of
                    // every caderno would otherwise pull the whole corpus into
                    // memory.
                    ->selectRaw("(documentation is not null and documentation <> '') as has_docs")
                    ->reorder('documentation_pages.title'),
            ])
            ->orderBy('name')
            ->get();

        return $notebooks
            ->map(function (Notebook $notebook) use ($status) {
                $pages = $notebook->pages
                    ->filter(fn (DocumentationPage $page) => $this->matchesStatus((bool) $page->has_docs, $status))
                    ->map(fn (DocumentationPage $page) => [
                        'title'   => $page->title,
                        'url'     => route('notebooks.pages.edit', [$notebook, $page]),
                        'hasDocs' => (bool) $page->has_docs,
                    ])
                    ->values();

                return [
                    'notebook' => [
                        'name'      => $notebook->name,
                        'slug'      => $notebook->slug,
                        'url'       => route('notebooks.show', $notebook),
                        'hasDocs'   => (bool) $notebook->has_docs,
                        'solutions' => $notebook->solutions
                            ->map(fn (Solution $solution) => [
                                'name' => $solution->name,
                                'url'  => route('solutions.show', $solution),
                            ])
                            ->all(),
                    ],
                    'pages' => $pages,
                    // Keeps the group visible if the caderno itself matches the
                    // status filter OR any of its pages survived it.
                    'keep' => $this->matchesStatus((bool) $notebook->has_docs, $status) || $pages->isNotEmpty(),
                ];
            })
            ->filter(fn (array $group) => $group['keep'])
            ->map(fn (array $group) => collect($group)->except('keep')->all())
            ->values();
    }

    /**
     * Solutions with NO documented caderno behind them — the actual gap the hub
     * exists to show, and the half that no longer has a home in `groups()` now
     * that the listing is per-notebook.
     *
     * @return Collection<int, array{name: string, slug: string, url: string, notebookCount: int}>
     */
    public function undocumentedSolutions(): Collection
    {
        return Solution::query()
            ->select('id', 'name', 'slug')
            ->whereDoesntHave('notebooks.documentedPages')
            ->withCount('notebooks')
            ->orderBy('name')
            ->get()
            ->map(fn (Solution $solution) => [
                'name' => $solution->name,
                'slug' => $solution->slug,
                'url'  => route('solutions.show', $solution),
                // Zero means nothing is linked at all; non-zero means a caderno
                // is linked but still empty — two different jobs to do, and the
                // count is what tells them apart.
                'notebookCount' => $solution->notebooks_count,
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
