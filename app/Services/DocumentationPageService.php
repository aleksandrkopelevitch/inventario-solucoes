<?php

namespace App\Services;

use App\Models\DocumentationPage;
use App\Models\Notebook;
use Illuminate\Support\Collection;
use Illuminate\Support\Str;

/**
 * Rules for the documentation page tree, all of them relative to one
 * `Notebook`.
 *
 * It used to take a bare `Model` because the container was polymorphic and two
 * controllers with two route families called into it. There is one container
 * and one controller now, so the type is concrete — which is also what let
 * `editUrl()` stop branching on what it was handed.
 *
 * The tree goes up to `DocumentationPage::MAX_DEPTH` levels
 * (`canReceiveChildren()`/`canBeNestedUnder()` are what refuse a deeper one,
 * counting the whole subtree being moved rather than just the page itself).
 * `position` orders a page among its SIBLINGS, so the notebook's `pages()`
 * relation — a flat `orderBy('position')` over roots and children alike — is
 * NOT reading order. Anything that shows the tree to a human walks `tree()`
 * instead; anything that only asks "is there content in here?" (coverage, the
 * flowSpec picker, slug uniqueness) can keep using the flat relation, since
 * depth doesn't change the answer.
 */
class DocumentationPageService
{
    /**
     * Static segments living under `notebooks/{notebook}/` — never allowed to
     * become a page slug, since `notebooks/{notebook}/{page}` would otherwise
     * be shadowed by them.
     *
     * These are the REAL segments. The list used to be PT-BR (`paginas`,
     * `titulo`, `mover`, `midia`, `compartilhar`) and had been stale since the
     * paths were anglicised — it reserved five words no route used while
     * leaving the five that mattered free to collide.
     */
    private const RESERVED_SLUGS = ['pages', 'share', 'context', 'chat', 'solutions', 'panel'];

    /**
     * A new page, at the end of its sibling list — a root by default, or a
     * child of `$parent`.
     *
     * The caller is what guarantees `$parent` is a legal one (in this notebook,
     * and itself a root): `StoreDocumentationPageRequest` validates both, so
     * an illegal nesting is a 422 naming the field rather than an exception
     * from in here.
     */
    public function create(Notebook $notebook, string $title, ?DocumentationPage $parent = null): DocumentationPage
    {
        $page = $notebook->pages()->make([
            'title'    => $title,
            'slug'     => $this->uniqueSlug($notebook, $title),
            'position' => $this->nextPosition($notebook, $parent),
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
     * Re-files the page under a DIFFERENT notebook. This is the other half of
     * the GitBook import: a space lands in one caderno, and its pages are then
     * moved out to the ones they really belong to, a page at a time.
     *
     * Four things have to happen together, and each is a way to break it:
     *
     * - **The notebook is set through the relation, not `update()`.**
     *   `notebook_id` is deliberately absent from DocumentationPage's
     *   `$fillable` (§ Security — no `$guarded = []`), so `associate()` is what
     *   sets it without widening mass assignment.
     * - **The slug is re-checked against the DESTINATION.** It is only unique
     *   per notebook (`unique(notebook_id, slug)`), so a page called "Visão
     *   geral" moving into a caderno that already has one would hit the index.
     *   The current slug is KEPT when it's free there — the URL changes
     *   notebook either way, and a gratuitously renumbered slug makes the move
     *   look like a rename.
     * - **Position goes to the end of the destination**, never carried over: a
     *   `position` of 3 from the old notebook would land in the middle of the
     *   new one's order for no reason the reader can see.
     * - **The nesting is resolved, never left straddling two notebooks.** A
     *   parent takes its children with it (they'd otherwise read as pages of
     *   one caderno while belonging to another one's tree); a CHILD moved on
     *   its own leaves its parent behind and therefore lands as a root — the
     *   alternative is a child whose parent lives somewhere else.
     *
     * Embedded media needs no work at all: it belongs to the PAGE, and
     * `MediaController::show()` authorizes on the collection name, so every
     * `/files/{id}` in the content keeps resolving.
     */
    public function moveToNotebook(DocumentationPage $page, Notebook $destination): void
    {
        $page->slug = $this->uniqueSlugFrom($destination, $page->slug);
        $page->position = $this->nextPosition($destination, null);
        $page->parent()->dissociate();
        $page->notebook()->associate($destination);
        $page->save();

        $this->moveDescendantsToNotebook($page, $destination);
    }

    /**
     * Re-files everything under `$page` into the same notebook, depth-first —
     * the whole subtree travels, not just the first level. With five levels in
     * play, moving only the direct children would leave the grandchildren
     * pointing at pages that now live in another caderno: rows still reachable
     * through their parent, but counted as documentation of a notebook they
     * were never filed under.
     *
     * Parents before children on purpose, so a child's slug is checked against
     * a destination that already holds its freshly resolved parent.
     */
    private function moveDescendantsToNotebook(DocumentationPage $page, Notebook $destination): void
    {
        foreach ($page->children()->get() as $child) {
            $child->slug = $this->uniqueSlugFrom($destination, $child->slug);
            $child->notebook()->associate($destination);
            $child->save();

            $this->moveDescendantsToNotebook($child, $destination);
        }
    }

    /**
     * Where a page can be moved to — every notebook except the one it already
     * sits in. Built once per request and handed to every row of the rail (see
     * the nav builder): per-row would be one query per page.
     *
     * A flat list now. It used to be `<optgroup>`s keyed by a PT-BR heading
     * ("Soluções" / "Grupos"), because the two kinds of container had to be
     * told apart in the picker; there is one kind, so the heading had nothing
     * left to say.
     *
     * @return array<int, array{value: int, label: string}>
     */
    public function destinationsFor(Notebook $current): array
    {
        return Notebook::orderBy('name')->get(['id', 'name'])
            ->reject(fn (Notebook $notebook) => $current->is($notebook))
            ->map(fn (Notebook $notebook) => ['value' => $notebook->id, 'label' => $notebook->name])
            ->values()
            ->all();
    }

    /**
     * The page's editor URL.
     *
     * Route names normally live in the controllers (which know their own
     * context), but a move answers with the DESTINATION's URL, which the
     * controller has no static way to name. It used to branch on the
     * container's class; with one container there is one route.
     */
    public function editUrl(DocumentationPage $page): string
    {
        return route('notebooks.pages.edit', [$page->notebook, $page]);
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
        $notebook = $page->notebook;
        // Explicit query, not the `parent` property: nothing guarantees the
        // page reaching here came from a single-row fetch, and strict mode's
        // violation would be a 500 on a delete (see the model's booted()).
        $parent = $page->parent()->first();
        $page->delete();

        return $parent ?? $this->firstPage($notebook);
    }

    /**
     * The notebook's pages in READING order — each page immediately followed
     * by its own subtree — carrying, per row, the depth to indent by and which
     * of the three structural gestures that row can actually perform.
     *
     * Those flags are here rather than in the nav builders for two reasons:
     * they're free (the whole flat list is already in memory, so no row costs
     * an extra query the way a per-row `children()->exists()` would), and
     * `canMove()` reads the very same flags to validate an incoming move — so
     * a button the rail doesn't offer is also a button nobody can forge.
     *
     * @return Collection<int, array{page: DocumentationPage, depth: int, hasChildren: bool, canNest: bool, canPromote: bool, canAddChild: bool, descendantLevels: int}>
     */
    public function tree(Notebook $notebook): Collection
    {
        $byParent = $notebook->pages()->get()->groupBy('parent_id');

        return $this->treeLevel($byParent, null, 0);
    }

    /**
     * One level of the walk, recursing into each page's own children.
     *
     * `$byParent` is the notebook's WHOLE page list grouped by `parent_id`
     * (one query, done by the caller), so the recursion is pure array work —
     * a per-node `children()` query here would be a query per page in the rail.
     *
     * @param  Collection<int|string|null, Collection<int, DocumentationPage>>  $byParent
     * @return Collection<int, array<string, mixed>>
     */
    private function treeLevel(Collection $byParent, ?int $parentId, int $depth): Collection
    {
        $siblings = $byParent->get($parentId === null ? '' : $parentId, collect())->values();

        return $siblings->flatMap(function (DocumentationPage $page, int $index) use ($byParent, $depth) {
            $children = $this->treeLevel($byParent, $page->id, $depth + 1);
            $height = 1 + (int) $children->max('descendantLevels');

            return collect([[
                'page'  => $page,
                'depth' => $depth,
                // Nesting means sliding under the sibling ABOVE, which only
                // exists from the second row on — and the whole subtree has to
                // fit: a page with subpages of its own drags them one level
                // down with it, so what is checked is its HEIGHT, never just
                // its own depth.
                'hasChildren' => $children->isNotEmpty(),
                'canNest'     => $index > 0 && $depth + $height <= DocumentationPage::MAX_DEPTH - 1,
                'canPromote'  => $depth > 0,
                'canAddChild' => $depth < DocumentationPage::MAX_DEPTH - 1,
                // Só pra o cálculo de altura do nível de cima — o consumidor
                // (rail, doc pública, card) ignora esta chave.
                'descendantLevels' => $height,
            ]])->concat($children);
        })->values();
    }

    /**
     * The tree as NAVIGATION rows: reading order, plus what a collapsible rail
     * needs to draw itself without a second query.
     *
     * Two flags carry the whole behaviour, and they are not the same thing:
     *
     * - **`expanded`** — this row's own children are shown. True for the
     *   ancestors of the active page and for the active page itself, so opening
     *   a page reveals the path that leads to it and nothing else. This is what
     *   GitBook does, and the reason it matters here is scale: the imported
     *   "Dados • BigQuery • GCP" is 133 pages, and a flat rail lists all of them
     *   at once.
     * - **`visible`** — this row is rendered at all. A root always is; anything
     *   else is only when its PARENT is expanded. Note it reads the parent's
     *   flag rather than walking up: rows arrive in reading order, so a parent
     *   is always decided before its children, and a collapsed branch therefore
     *   hides its whole subtree without any recursion here.
     *
     * The client can flip both afterwards (`docs-tree.js`); the server decides
     * the state a page LOADS in, which is what makes the rail correct with
     * JavaScript still parsing.
     *
     * @return Collection<int, array<string, mixed>>
     */
    public function navRows(Notebook $notebook, ?DocumentationPage $active): Collection
    {
        $open = $this->ancestorIds($active);

        if ($active) {
            // The active page opens too — landing on a section should show what
            // is inside it, not just that it has something.
            $open[$active->id] = true;
        }

        $expandedByPage = [];

        return $this->tree($notebook)->map(function (array $row) use ($open, &$expandedByPage) {
            /** @var DocumentationPage $page */
            $page = $row['page'];
            $expanded = isset($open[$page->id]);
            $expandedByPage[$page->id] = $expanded;

            return $row + [
                'id'       => $page->id,
                'parentId' => $page->parent_id,
                'expanded' => $expanded,
                'visible'  => $page->parent_id === null || ($expandedByPage[$page->parent_id] ?? false),
            ];
        });
    }

    /**
     * The ids on the path from `$page` up to a root, as a set — `$page` itself
     * excluded.
     *
     * `parent()->first()`, never the `parent` property: the caller may hand us a
     * page from a multi-row hydration, where strict mode turns a lazy load into
     * a 500 (§ Strict mode).
     *
     * @return array<int, true>
     */
    private function ancestorIds(?DocumentationPage $page): array
    {
        $ids = [];
        $ancestor = $page?->parent()->first();

        while ($ancestor) {
            $ids[$ancestor->id] = true;
            $ancestor = $ancestor->parent()->first();
        }

        return $ids;
    }

    /**
     * Whether a structural move is available for this page — answered from the
     * same tree the rail renders, so the UI and the validation can never
     * disagree about which arrows a page has. `up`/`down` are always allowed:
     * at the ends of a list they no-op, as they always have.
     */
    public function canMove(DocumentationPage $page, string $direction): bool
    {
        $row = $this->tree($page->notebook)->first(fn (array $candidate) => $candidate['page']->is($page));

        return match ($direction) {
            'in'    => (bool) ($row['canNest'] ?? false),
            'out'   => (bool) ($row['canPromote'] ?? false),
            default => true,
        };
    }

    /**
     * The page a notebook opens on — the first ROOT, not the lowest
     * `position` (a child's position is only meaningful among its siblings, so
     * the flat relation could hand back a subpage). The second query is a
     * safety net for data that predates or side-steps the cascade.
     */
    public function firstPage(Notebook $notebook): ?DocumentationPage
    {
        return $notebook->pages()->whereNull('parent_id')->first()
            ?? $notebook->pages()->first();
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

        if (! $parent || ! $page->canBeNestedUnder($parent)) {
            return;
        }

        $page->position = $this->nextPosition($page->notebook, $parent);
        $page->parent()->associate($parent);
        $page->save();
    }

    /**
     * Promotes a page one level UP, landing right after the parent it left —
     * the rail is how people navigate, and a page that reappears at the very
     * bottom of a list reads as lost rather than promoted. Making room means
     * shifting the parent's own siblings below it down by one.
     *
     * Note what it is promoted INTO: the parent's sibling set, which is the
     * root list only when the parent was a root. A sub-subpage promoted becomes
     * a subpage of its grandparent, not a root — one step at a time, which is
     * also what the rail's arrow implies.
     */
    private function promote(DocumentationPage $page): void
    {
        $parent = $page->parent()->first();

        if (! $parent) {
            return;
        }

        $page->notebook->pages()
            ->where('parent_id', $parent->parent_id)
            ->where('position', '>', $parent->position)
            ->increment('position');

        $page->position = $parent->position + 1;
        // `associate(null)` and not `dissociate()`: the page joins the
        // GRANDPARENT, which is null only when the parent was a root.
        $page->parent()->associate($parent->parent_id ? $parent->parent()->first() : null);
        $page->save();
    }

    /** @return Collection<int, DocumentationPage> */
    private function siblingsOf(DocumentationPage $page): Collection
    {
        return $page->notebook->pages()->where('parent_id', $page->parent_id)->get();
    }

    private function previousSibling(DocumentationPage $page): ?DocumentationPage
    {
        $siblings = $this->siblingsOf($page);
        $index = $siblings->search(fn (DocumentationPage $p) => $p->is($page));

        return $index > 0 ? $siblings->get($index - 1) : null;
    }

    /** End of the sibling list the page is joining — the roots of `$notebook`, or `$parent`'s children. */
    private function nextPosition(Notebook $notebook, ?DocumentationPage $parent): int
    {
        $query = $parent
            ? $parent->children()
            : $notebook->pages()->whereNull('parent_id');

        return (int) $query->max('position') + 1;
    }

    private function uniqueSlug(Notebook $notebook, string $title): string
    {
        return $this->uniqueSlugFrom($notebook, Str::slug($title) ?: 'pagina');
    }

    /** Same rule starting from an existing slug rather than a title — used when a page changes notebook. */
    private function uniqueSlugFrom(Notebook $notebook, string $base): string
    {
        $base = $base ?: 'pagina';
        $slug = $base;
        $suffix = 1;

        while (in_array($slug, self::RESERVED_SLUGS, true) || $notebook->pages()->where('slug', $slug)->exists()) {
            $slug = $base . '-' . (++$suffix);
        }

        return $slug;
    }
}
