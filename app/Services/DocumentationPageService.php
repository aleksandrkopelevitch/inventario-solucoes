<?php

namespace App\Services;

use App\Models\DocumentationGroup;
use App\Models\DocumentationPage;
use App\Models\Solution;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Str;

/**
 * Rules for the documentation page tree (flat list, ordered by `position`),
 * shared between `SolutionDocumentationController` and
 * `DocumentationGroupPageController` — the container is polymorphic
 * (`Solution` or `DocumentationGroup`), both expose the same `pages()` relation.
 */
class DocumentationPageService
{
    /** Segments used by static routes — never becomes a page slug, or it would collide with them. */
    private const RESERVED_SLUGS = ['paginas', 'titulo', 'mover', 'midia', 'compartilhar'];

    public function create(Model $container, string $title): DocumentationPage
    {
        $position = (int) $container->pages()->max('position') + 1;

        return $container->pages()->create([
            'title'    => $title,
            'slug'     => $this->uniqueSlug($container, $title),
            'position' => $position,
        ]);
    }

    /** Only the title changes — the slug (and therefore the URL) stays stable. */
    public function rename(DocumentationPage $page, string $title): void
    {
        $page->update(['title' => $title]);
    }

    /** Swaps the page's position with its neighbor (previous or next) in the ordered list. */
    public function move(DocumentationPage $page, string $direction): void
    {
        $pages = $page->container->pages()->get();
        $index = $pages->search(fn (DocumentationPage $p) => $p->is($page));
        $neighbor = $pages->get($direction === 'up' ? $index - 1 : $index + 1);

        if (! $neighbor) {
            return;
        }

        [$pagePosition, $neighborPosition] = [$page->position, $neighbor->position];
        $page->update(['position' => $neighborPosition]);
        $neighbor->update(['position' => $pagePosition]);
    }

    /**
     * Re-files the page under a DIFFERENT container — the Solution (or group)
     * it actually belongs to. This is the other half of the GitBook import: a
     * space lands in one group, and its pages are then moved out to the
     * solutions they document, one at a time.
     *
     * Three things have to happen together, and each is a way to break it:
     *
     * - **The container is set through the relation, not `update()`.**
     *   `container_type`/`container_id` are deliberately absent from
     *   DocumentationPage's `$fillable` (§ Security — no `$guarded = []`), so
     *   `associate()` is what sets them without widening mass assignment.
     * - **The slug is re-checked against the DESTINATION.** It is only unique
     *   per container (`unique(container_type, container_id, slug)`), so a page
     *   called "Visão geral" moving into a solution that already has one would
     *   hit the index. The current slug is KEPT when it's free there — the URL
     *   changes container either way, and a gratuitously renumbered slug makes
     *   the move look like a rename.
     * - **Position goes to the end of the destination**, never carried over: a
     *   `position` of 3 from the old container would land in the middle of the
     *   new one's order for no reason the reader can see.
     *
     * Embedded media needs no work at all: it belongs to the PAGE, and
     * `MediaController::show()` authorizes on the collection name, so every
     * `/files/{id}` in the content keeps resolving.
     */
    public function moveToContainer(DocumentationPage $page, Model $destination): void
    {
        $page->slug = $this->uniqueSlugFrom($destination, $page->slug);
        $page->position = (int) $destination->pages()->max('position') + 1;
        $page->container()->associate($destination);
        $page->save();
    }

    /**
     * Where a page can be moved to, as `<optgroup>`-ready lists keyed by their
     * PT-BR heading — every Solution and every standalone group except the one
     * it already sits in. Built once per request and handed to every row of the
     * rail (see the nav builders): per-row would be one query per page.
     *
     * @return array<string, array<int, array{value: string, label: string}>>
     */
    public function destinationsFor(Model $current): array
    {
        $solutions = Solution::orderBy('name')->get(['id', 'name'])
            ->reject(fn (Solution $solution) => $current instanceof Solution && $current->is($solution))
            ->map(fn (Solution $solution) => ['value' => 'solution:' . $solution->id, 'label' => $solution->name])
            ->values()
            ->all();

        $groups = DocumentationGroup::orderBy('name')->get(['id', 'name'])
            ->reject(fn (DocumentationGroup $group) => $current instanceof DocumentationGroup && $current->is($group))
            ->map(fn (DocumentationGroup $group) => ['value' => 'group:' . $group->id, 'label' => $group->name])
            ->values()
            ->all();

        return array_filter(['Soluções' => $solutions, 'Grupos' => $groups]);
    }

    /**
     * The page's editor URL, resolved from whatever its container turned out to
     * be. Route names normally live in the controllers (which know their own
     * context), but a move answers with the DESTINATION's URL — which the
     * source controller has no static way to name.
     */
    public function editUrl(DocumentationPage $page): string
    {
        $container = $page->container;

        return $container instanceof DocumentationGroup
            ? route('documentation.groups.pages.edit', [$container, $page])
            : route('solutions.docs.page.edit', [$container, $page]);
    }

    /** Deletes the page (embedded media goes with it, via Spatie) and returns the container's next page, if any remain. */
    public function delete(DocumentationPage $page): ?DocumentationPage
    {
        $container = $page->container;
        $page->delete();

        return $container->pages()->first();
    }

    private function uniqueSlug(Model $container, string $title): string
    {
        return $this->uniqueSlugFrom($container, Str::slug($title) ?: 'pagina');
    }

    /** Same rule starting from an existing slug rather than a title — used when a page changes container. */
    private function uniqueSlugFrom(Model $container, string $base): string
    {
        $base = $base ?: 'pagina';
        $slug = $base;
        $suffix = 1;

        while (in_array($slug, self::RESERVED_SLUGS, true) || $container->pages()->where('slug', $slug)->exists()) {
            $slug = $base . '-' . (++$suffix);
        }

        return $slug;
    }
}
