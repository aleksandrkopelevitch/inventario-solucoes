<?php

namespace App\Models;

use App\Contracts\Documentable;
use Database\Factories\DocumentationPageFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Spatie\MediaLibrary\InteractsWithMedia;

/**
 * A documentation page (Markdown + GitBook notation), the atomic unit edited by
 * the Editor.js block editor — and, since diagrams stopped carrying prose of
 * their own, the ONLY kind of documentation in this app. A `Notebook` has 1..N
 * of them, in a tree up to `MAX_DEPTH` levels deep (five) ordered by
 * `position`.
 *
 * The owner used to be a polymorphic `container` — a `Solution` or a standalone
 * `DocumentationGroup` — and it is a plain `notebook_id` now. That collapse is
 * what let the two duplicate controller families, the two route families and
 * every `instanceof` branch that turned a page into a URL go away. A page
 * reaches a Solution the long way round, through `Notebook::solutions()`, and
 * therefore reaches as many of them as the notebook actually documents.
 *
 * A page CITES drawings rather than owning one. `diagram_id` is gone: a page
 * carries `{% diagram %}` blocks in its text, as many as the prose needs, which
 * is what a reference actually is. The FK modelled "this page is the page of
 * that drawing", which was never true of more than a handful of them.
 *
 * The tree's shape is deliberately capped, and both halves of the cap matter:
 *
 * - **Nothing may end up deeper than `MAX_DEPTH`.** A page's own depth is not
 *   enough to decide a nesting: what has to fit is the whole SUBTREE being
 *   moved, so both checks take the mover's height into account
 *   (`canReceiveChildren()` / `canBeNestedUnder()`).
 * - **A child lives in its parent's notebook.** Both halves of a nesting are
 *   re-checked on every move (`DocumentationPageService::moveToNotebook()`
 *   carries the whole subtree along; a child moved on its own is promoted to
 *   a root at the destination), so a page can never be filed under a parent
 *   that belongs to somebody else's caderno.
 *
 * `position` orders a page among its SIBLINGS — the pages sharing its
 * `parent_id` — not among everything in the notebook, so reading order is a
 * walk of the tree (`DocumentationPageService::tree()`) rather than a plain
 * `orderBy('position')`.
 */
class DocumentationPage extends Model implements Documentable
{
    /** @use HasFactory<DocumentationPageFactory> */
    use HasFactory, InteractsWithMedia;

    /**
     * How many levels the tree may have. Everything that enforces the cap reads
     * THIS — the depth is a knob, not a shape hard-coded across the module — so
     * changing it is one edit here plus one literal indent step per level in the
     * three views that draw the tree (the pages rail, the public documentation
     * index and the solution detail card): Tailwind only ships classes it can
     * SEE in the source, so those steps cannot be computed.
     *
     * Five since 2026-08-26, and the number is not arbitrary: the GitBook
     * corpus this app imported nests to exactly five, so anything less makes
     * `GitbookPageTree` collapse the tail of two spaces into breadcrumb titles
     * (it was 86 of 629 pages at three).
     */
    public const MAX_DEPTH = 5;

    /**
     * `parent_id` and `notebook_id` are deliberately absent (§ Security: no
     * `$guarded = []`) — every relation this page holds is set through the
     * relation itself (`parent()->associate()`, `notebook()->associate()`),
     * never mass-assigned from a request payload.
     */
    protected $fillable = [
        'title',
        'slug',
        'documentation',
        'position',
    ];

    public function getRouteKeyName(): string
    {
        return 'slug';
    }

    public function registerMediaCollections(): void
    {
        // See Solution/Diagram — documentation media, served by
        // `files.show`, referenced as /files/{id} in the Markdown.
        $this->addMediaCollection(self::DOCS_COLLECTION);
    }

    public function documentationTitle(): string
    {
        return $this->title;
    }

    /** The caderno this page belongs to — its one and only owner. */
    public function notebook(): BelongsTo
    {
        return $this->belongsTo(Notebook::class);
    }

    public function parent(): BelongsTo
    {
        return $this->belongsTo(self::class, 'parent_id');
    }

    public function children(): HasMany
    {
        return $this->hasMany(self::class, 'parent_id')->orderBy('position');
    }

    /** A page at the top of the tree. */
    public function isRoot(): bool
    {
        return $this->parent_id === null;
    }

    /** 0 for a root, 1 for a subpage, and so on up to `MAX_DEPTH - 1`. */
    public function depth(): int
    {
        $depth = 0;
        // `parent()->first()`, never the `parent` property: this runs on pages
        // that may have come from a multi-row hydration, where strict mode
        // turns a lazy load into a 500 (see `booted()` below).
        $ancestor = $this->parent()->first();

        while ($ancestor) {
            $depth++;
            $ancestor = $ancestor->parent()->first();
        }

        return $depth;
    }

    /** 1 for a leaf, 2 for a page with children, 3 for one with grandchildren, … */
    public function subtreeHeight(): int
    {
        $children = $this->children()->get();

        return $children->isEmpty() ? 1 : 1 + $children->map->subtreeHeight()->max();
    }

    /** Whether a child could be filed under this page without passing `MAX_DEPTH`. */
    public function canReceiveChildren(): bool
    {
        return $this->depth() < self::MAX_DEPTH - 1;
    }

    /**
     * Whether this page — with everything hanging off it — fits under `$parent`.
     *
     * The page's own new depth is the easy half; the half that gets forgotten
     * is its SUBTREE. Moving a page that already has subpages one level down
     * pushes those subpages down too, so a page whose descendants would land
     * past the cap can't be nested even though the page itself would fit.
     */
    public function canBeNestedUnder(self $parent): bool
    {
        return $parent->depth() + $this->subtreeHeight() <= self::MAX_DEPTH - 1;
    }

    /**
     * Children go through their own model so Spatie's `deleting` hook runs and
     * their embedded media is cleaned up — same reasoning as
     * `Notebook::booted()`. The FK's `cascadeOnDelete` still guards the rows
     * against a delete that bypasses Eloquent; it just can't delete files.
     *
     * `children()->get()`, never the `children` property: deleting a whole
     * notebook deletes its pages from a MULTI-ROW hydration
     * (`Notebook::booted()` maps over its pages), which is exactly where strict
     * mode arms — and a lazy load here would 500 the
     * delete of any group holding more than one page. An explicit query can't
     * violate anything. A subpage already removed with its parent earlier in
     * that same loop deletes a second time as a no-op (0 rows).
     */
    protected static function booted(): void
    {
        static::deleting(fn (self $page) => $page->children()->get()->each->delete());
    }
}
