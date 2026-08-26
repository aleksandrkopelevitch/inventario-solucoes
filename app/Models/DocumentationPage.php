<?php

namespace App\Models;

use App\Contracts\Documentable;
use Database\Factories\DocumentationPageFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\MorphTo;
use Spatie\MediaLibrary\InteractsWithMedia;

/**
 * A documentation page (Markdown + GitBook notation), the atomic unit
 * edited by the Editor.js block — same as the old single blob on
 * `Solution`/`Integration`, except now a Solution (or a standalone
 * `DocumentationGroup`) can have 1..N of them, in a tree up to `MAX_DEPTH`
 * levels deep ordered by `position`. `container` is polymorphic (`Solution` or
 * `DocumentationGroup`).
 *
 * The tree's shape is deliberately capped, and both halves of the cap matter:
 *
 * - **Nothing may end up deeper than `MAX_DEPTH`.** A page's own depth is not
 *   enough to decide a nesting: what has to fit is the whole SUBTREE being
 *   moved, so both checks take the mover's height into account
 *   (`canReceiveChildren()` / `canBeNestedUnder()`).
 * - **A child lives in its parent's container.** Both halves of a nesting are
 *   re-checked on every move (`DocumentationPageService::moveToContainer()`
 *   carries the whole subtree along; a child moved on its own is promoted to
 *   a root at the destination), so a page can never be filed under a parent
 *   that belongs to somebody else's solution.
 *
 * `position` orders a page among its SIBLINGS — the pages sharing its
 * `parent_id` — not among everything in the container, so reading order is a
 * walk of the tree (`DocumentationPageService::tree()`) rather than a plain
 * `orderBy('position')`.
 */
class DocumentationPage extends Model implements Documentable
{
    /** @use HasFactory<DocumentationPageFactory> */
    use HasFactory, InteractsWithMedia;

    /**
     * How many levels the tree may have: a page, a subpage and a sub-subpage.
     * Everything that enforces the cap reads THIS — the depth is a knob, not a
     * shape hard-coded across the module, so raising or lowering it is one
     * edit here plus the indent step in the rail's view.
     */
    public const MAX_DEPTH = 3;

    /**
     * `parent_id` is deliberately absent — like `container_type`/`container_id`
     * (§ Security: no `$guarded = []`), the page's place in the tree is set
     * through the relation (`parent()->associate()`), never mass-assigned from
     * a request payload.
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
        // See Solution/Integration — documentation media, served by
        // `files.show`, referenced as /files/{id} in the Markdown.
        $this->addMediaCollection(self::DOCS_COLLECTION);
    }

    public function documentationTitle(): string
    {
        return $this->title;
    }

    public function container(): MorphTo
    {
        return $this->morphTo();
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

    /** 0 for a root, 1 for a subpage, 2 for a sub-subpage. */
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

    /** 1 for a leaf, 2 for a page with children, 3 for a page with grandchildren. */
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
     * `DocumentationGroup::booted()`. The FK's `cascadeOnDelete` still guards
     * the rows against a delete that bypasses Eloquent; it just can't delete
     * files.
     *
     * `children()->get()`, never the `children` property: deleting a whole
     * container deletes its pages from a MULTI-ROW hydration
     * (`DocumentationGroup::booted()` maps over `$group->pages`), which is
     * exactly where strict mode arms — and a lazy load here would 500 the
     * delete of any group holding more than one page. An explicit query can't
     * violate anything. A subpage already removed with its parent earlier in
     * that same loop deletes a second time as a no-op (0 rows).
     */
    protected static function booted(): void
    {
        static::deleting(fn (self $page) => $page->children()->get()->each->delete());
    }
}
