<?php

namespace App\Services;

use App\Models\DocumentationGroup;
use App\Models\DocumentationPage;
use App\Models\Solution;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Collection;
use Illuminate\Support\Str;

/**
 * Rules for the documentation page tree, shared between
 * `SolutionDocumentationController` and `DocumentationGroupPageController` —
 * the container is polymorphic (`Solution` or `DocumentationGroup`), both
 * expose the same `pages()` relation.
 *
 * The tree is TWO levels deep: root pages, each optionally holding children
 * (`DocumentationPage::canBeNested()` is what refuses a third). `position`
 * orders a page among its SIBLINGS, so the container's `pages()` relation — a
 * flat `orderBy('position')` over roots and children alike — is NOT reading
 * order. Anything that shows the tree to a human walks `tree()` instead;
 * anything that only asks "is there content in here?" (coverage, the flowSpec
 * picker, slug uniqueness) can keep using the flat relation, since depth
 * doesn't change the answer.
 */
class DocumentationPageService
{
    /** Segments used by static routes — never becomes a page slug, or it would collide with them. */
    private const RESERVED_SLUGS = ['paginas', 'titulo', 'mover', 'midia', 'compartilhar'];

    /**
     * A new page, at the end of its sibling list — a root by default, or a
     * child of `$parent`.
     *
     * The caller is what guarantees `$parent` is a legal one (in this container,
     * and itself a root): `StoreDocumentationPageRequest` validates both, so
     * an illegal nesting is a 422 naming the field rather than an exception
     * from in here.
     */
    public function create(Model $container, string $title, ?DocumentationPage $parent = null): DocumentationPage
    {
        $page = $container->pages()->make([
            'title'    => $title,
            'slug'     => $this->uniqueSlug($container, $title),
            'position' => $this->nextPosition($container, $parent),
        ]);

        // `parent_id` is not fillable (see the model) — the tree is set through
        // the relation, never mass-assigned.
        $page->parent()->associate($parent);
        $page->save();

        return $page;
    }

    /** Only the title changes — the slug (and therefore the URL) stays stable. */
    public function rename(DocumentationPage $page, string $title): void
    {
        $page->update(['title' => $title]);
    }

    /**
     * The four gestures the rail offers, all of them relative to where the page
     * already is:
     *
     * - `up`/`down` reorder it among its siblings (the pages sharing its
     *   `parent_id`) — a child never jumps out of its parent by being moved up
     *   past the first position, it simply stops moving.
     * - `in` nests it under the sibling right above it (GitBook's Tab).
     * - `out` promotes a child back to the root list, landing right after the
     *   parent it left.
     *
     * `in`/`out` are only offered when they're possible, and
     * `MoveDocumentationPageRequest` re-checks that server-side, so reaching
     * here with an impossible one is already a 422. The no-ops below are for
     * `up`/`down` at the ends of a list, which have always been silent.
     */
    public function move(DocumentationPage $page, string $direction): void
    {
        match ($direction) {
            'in'    => $this->nest($page),
            'out'   => $this->promote($page),
            default => $this->reorder($page, $direction),
        };
    }

    /**
     * Re-files the page under a DIFFERENT container — the Solution (or group)
     * it actually belongs to. This is the other half of the GitBook import: a
     * space lands in one group, and its pages are then moved out to the
     * solutions they document, one at a time.
     *
     * Four things have to happen together, and each is a way to break it:
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
     * - **The nesting is resolved, never left straddling two containers.** A
     *   parent takes its children with it (they'd otherwise read as pages of a
     *   solution while belonging to another one's tree); a CHILD moved on its
     *   own leaves its parent behind and therefore lands as a root — the
     *   alternative is a child whose parent lives somewhere else.
     *
     * Embedded media needs no work at all: it belongs to the PAGE, and
     * `MediaController::show()` authorizes on the collection name, so every
     * `/files/{id}` in the content keeps resolving.
     */
    public function moveToContainer(DocumentationPage $page, Model $destination): void
    {
        $children = $page->children()->get();

        $page->slug = $this->uniqueSlugFrom($destination, $page->slug);
        $page->position = $this->nextPosition($destination, null);
        $page->parent()->dissociate();
        $page->container()->associate($destination);
        $page->save();

        // After the parent, so a child's slug is also checked against the
        // parent's freshly resolved one.
        foreach ($children as $child) {
            $child->slug = $this->uniqueSlugFrom($destination, $child->slug);
            $child->container()->associate($destination);
            $child->save();
        }
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

    /**
     * Deletes the page — and, with it, its children (through their own models,
     * so Spatie cleans up their media: see `DocumentationPage::booted()`).
     *
     * Returns where the reader should go next: the parent of a deleted child —
     * the closest thing to "where you were" — or the top of the tree, or null
     * once nothing is left.
     */
    public function delete(DocumentationPage $page): ?DocumentationPage
    {
        $container = $page->container;
        // Explicit query, not the `parent` property: nothing guarantees the
        // page reaching here came from a single-row fetch, and strict mode's
        // violation would be a 500 on a delete (see the model's booted()).
        $parent = $page->parent()->first();
        $page->delete();

        return $parent ?? $this->firstPage($container);
    }

    /**
     * The container's pages in READING order — each root immediately followed
     * by its children — carrying, per row, the depth to indent by and which of
     * the two nesting gestures that row can actually perform.
     *
     * Those flags are here rather than in the nav builders for two reasons:
     * they're free (the whole flat list is already in memory, so no row costs
     * an extra query the way a per-row `children()->exists()` would), and
     * `canMove()` reads the very same flags to validate an incoming move — so
     * a button the rail doesn't offer is also a button nobody can forge.
     *
     * @return Collection<int, array{page: DocumentationPage, depth: int, hasChildren: bool, canNest: bool, canPromote: bool}>
     */
    public function tree(Model $container): Collection
    {
        $pages = $container->pages()->get();
        $children = $pages->reject(fn (DocumentationPage $page) => $page->isRoot())->groupBy('parent_id');

        return $pages
            ->filter(fn (DocumentationPage $page) => $page->isRoot())
            ->values()
            ->flatMap(function (DocumentationPage $root, int $index) use ($children) {
                $own = $children->get($root->id, collect());

                return collect([[
                    'page'  => $root,
                    'depth' => 0,
                    // Nesting a root means sliding it under the root ABOVE it,
                    // which only exists from the second row on — and only a
                    // childless page can go there without taking children of
                    // its own down to a third level.
                    'hasChildren' => $own->isNotEmpty(),
                    'canNest'     => $index > 0 && $own->isEmpty(),
                    'canPromote'  => false,
                ]])->concat($own->map(fn (DocumentationPage $child) => [
                    'page'        => $child,
                    'depth'       => 1,
                    'hasChildren' => false,
                    'canNest'     => false,
                    'canPromote'  => true,
                ]));
            })
            ->values();
    }

    /**
     * Whether a nesting move is available for this page — answered from the
     * same tree the rail renders, so the UI and the validation can never
     * disagree about which arrows a page has. `up`/`down` are always allowed:
     * at the ends of a list they no-op, as they always have.
     */
    public function canMove(DocumentationPage $page, string $direction): bool
    {
        $row = $this->tree($page->container)->first(fn (array $candidate) => $candidate['page']->is($page));

        return match ($direction) {
            'in'    => (bool) ($row['canNest'] ?? false),
            'out'   => (bool) ($row['canPromote'] ?? false),
            default => true,
        };
    }

    /**
     * The page a container opens on — the first ROOT, not the lowest
     * `position` (a child's position is only meaningful among its siblings, so
     * the flat relation could hand back a subpage). The second query is a
     * safety net for data that predates or side-steps the cascade.
     */
    public function firstPage(Model $container): ?DocumentationPage
    {
        return $container->pages()->whereNull('parent_id')->first()
            ?? $container->pages()->first();
    }

    /** Swaps the page's position with its neighbor (previous or next) among its siblings. */
    private function reorder(DocumentationPage $page, string $direction): void
    {
        $siblings = $this->siblingsOf($page);
        $index = $siblings->search(fn (DocumentationPage $p) => $p->is($page));
        $neighbor = $siblings->get($direction === 'up' ? $index - 1 : $index + 1);

        if (! $neighbor) {
            return;
        }

        [$pagePosition, $neighborPosition] = [$page->position, $neighbor->position];
        $page->update(['position' => $neighborPosition]);
        $neighbor->update(['position' => $pagePosition]);
    }

    /** Nests the page under the sibling immediately above it, as that one's last child. */
    private function nest(DocumentationPage $page): void
    {
        $parent = $this->previousSibling($page);

        if (! $parent || ! $page->canBeNested() || ! $parent->canReceiveChildren()) {
            return;
        }

        $page->position = $this->nextPosition($page->container, $parent);
        $page->parent()->associate($parent);
        $page->save();
    }

    /**
     * Promotes a child back to the root list, right AFTER the parent it left —
     * the rail is how people navigate, and a page that reappears at the very
     * bottom of it reads as lost rather than promoted. Making room means
     * shifting the roots below the parent down by one.
     */
    private function promote(DocumentationPage $page): void
    {
        $parent = $page->parent()->first();

        if (! $parent) {
            return;
        }

        $page->container->pages()
            ->whereNull('parent_id')
            ->where('position', '>', $parent->position)
            ->increment('position');

        $page->position = $parent->position + 1;
        $page->parent()->dissociate();
        $page->save();
    }

    /** @return Collection<int, DocumentationPage> */
    private function siblingsOf(DocumentationPage $page): Collection
    {
        return $page->container->pages()->where('parent_id', $page->parent_id)->get();
    }

    private function previousSibling(DocumentationPage $page): ?DocumentationPage
    {
        $siblings = $this->siblingsOf($page);
        $index = $siblings->search(fn (DocumentationPage $p) => $p->is($page));

        return $index > 0 ? $siblings->get($index - 1) : null;
    }

    /** End of the sibling list the page is joining — the roots of `$container`, or `$parent`'s children. */
    private function nextPosition(Model $container, ?DocumentationPage $parent): int
    {
        $query = $parent
            ? $parent->children()
            : $container->pages()->whereNull('parent_id');

        return (int) $query->max('position') + 1;
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
