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
 * `DocumentationGroup`) can have 1..N of them, in a tree TWO levels deep
 * ordered by `position`. `container` is polymorphic (`Solution` or
 * `DocumentationGroup`).
 *
 * The tree's shape is deliberately capped, and both halves of the cap matter:
 *
 * - **A parent must be a root.** `parent_id` may only point at a page whose own
 *   `parent_id` is null, which is what keeps the tree at two levels instead of
 *   an arbitrary depth (`canReceiveChildren()` / `canBeNested()`).
 * - **A child lives in its parent's container.** Both halves of a nesting are
 *   re-checked on every move (`DocumentationPageService::moveToContainer()`
 *   carries a parent's children along; a child moved on its own is promoted to
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

    /** A page at the top of the tree — the only kind that may hold children. */
    public function isRoot(): bool
    {
        return $this->parent_id === null;
    }

    /** Level 1 of 2: whether this page may receive children at all. */
    public function canReceiveChildren(): bool
    {
        return $this->isRoot();
    }

    /**
     * Whether this page may become someone's child. A root WITH children can't:
     * its own children would land on a third level. This is the check that
     * makes "two levels" a property of the data and not just of the UI.
     */
    public function canBeNested(): bool
    {
        return $this->isRoot() && $this->children()->doesntExist();
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
