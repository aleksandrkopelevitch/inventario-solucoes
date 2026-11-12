<?php

namespace App\Models;

use App\Contracts\Documentable;
use App\Models\Concerns\HasWebImageRendition;
use App\Support\Documentation\PageTextSql;
use App\Support\Documentation\SecretText;
use Database\Factories\DocumentationPageFactory;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Spatie\MediaLibrary\InteractsWithMedia;
use Spatie\MediaLibrary\MediaCollections\Models\Media;

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
    use HasFactory, HasWebImageRendition, InteractsWithMedia {
        // Both traits define it; the rendition's wins (it calls nothing of the other's).
        HasWebImageRendition::registerMediaConversions insteadof InteractsWithMedia;
    }

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
     * The uploads that get a WEB_CONVERSION. Not GIF, whose animation GD would
     * flatten to its first frame; not SVG, which is already as small as it
     * gets and would only lose its vectors.
     */
    public const WEB_IMAGE_TYPES = ['image/png', 'image/jpeg', 'image/webp'];

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

    /**
     * The page WITHOUT its text: what a tree walk needs (the rail, a move's
     * validation, the child-page cards), with the one fact those walks ask of
     * the text answered in SQL — `has_content`. The `documentation` column is
     * the whole corpus, and every screen drawing a 630-page caderno's tree
     * used to pull all 5 MB of it to answer "is there anything here?" per row.
     * Measured on the 133-page caderno: 3.7 ms instead of 15 ms, and no 2 MB
     * of strings in PHP.
     *
     * A row hydrated this way answers `hasContent()` from the alias; one
     * hydrated whole answers it from the text. Reading `$page->documentation`
     * on a light row is null, so anything that needs the text fetches it (see
     * `DocumentationSearchService::build()`).
     */
    public function scopeLight(Builder $query): void
    {
        $query->select(['id', 'notebook_id', 'parent_id', 'title', 'slug', 'position', 'created_by_id', 'updated_by_id', 'created_at', 'updated_at'])
            ->selectRaw(PageTextSql::hasContentExpression($query->getConnection()) . ' as ' . PageTextSql::HAS_CONTENT_ALIAS);
    }

    /**
     * Adds the text's md5 (`textHash()`) to a light row — for the search
     * index's key, which proves the index fresh by content. The hash costs
     * the database a read of every page, so it is not part of `light()`.
     */
    public function scopeWithTextHash(Builder $query): void
    {
        $query->selectRaw(PageTextSql::md5Expression($query->getConnection()) . ' as ' . PageTextSql::MD5_ALIAS);
    }

    /** Whether there is anything to read once whitespace is stripped. */
    public function hasContent(): bool
    {
        if (array_key_exists(PageTextSql::HAS_CONTENT_ALIAS, $this->attributes)) {
            return (bool) $this->attributes[PageTextSql::HAS_CONTENT_ALIAS];
        }

        return trim((string) $this->documentation) !== '';
    }

    /** md5 of the text ('' for none) — from SQL on a light row, from the text otherwise. */
    public function textHash(): string
    {
        if (array_key_exists(PageTextSql::MD5_ALIAS, $this->attributes)) {
            return (string) $this->attributes[PageTextSql::MD5_ALIAS];
        }

        return md5((string) $this->documentation);
    }

    public function registerMediaCollections(): void
    {
        // See Solution/Diagram — documentation media, served by
        // `files.show`, referenced as /files/{id} in the Markdown.
        $this->addMediaCollection(self::DOCS_COLLECTION);
    }

    /* The WEB_CONVERSION of every image in `docs`: HasWebImageRendition. */

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

    /**
     * Who created the page, and who saved it last — both written by the hooks
     * in `booted()`, never from a payload. `withTrashed()` because users are
     * soft-deleted and somebody who left is still who wrote the page.
     */
    public function creator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by_id')->withTrashed();
    }

    public function updater(): BelongsTo
    {
        return $this->belongsTo(User::class, 'updated_by_id')->withTrashed();
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
     *
     * Authorship rides on the same save that moves `updated_at`, so the two
     * always describe ONE event: whoever caused this save is the last updater,
     * whatever changed — content, title or place in the tree. A save with
     * nothing dirty is not a save (Eloquent skips it), and one made with nobody
     * signed in (the GitBook import, tinker) records nobody rather than keeping
     * a name that no longer goes with the date.
     *
     * Every protected value is stored with its id (`SecretText::withIds()`),
     * whoever wrote the text — the editor's saves already arrive with them, so
     * this is for every OTHER writer. `SecretText::mask()` relies on it: a
     * value stored without an id could only be masked with a marker no save
     * can resolve. A no-op when nothing lacks one, so it marks nothing dirty.
     */
    protected static function booted(): void
    {
        static::creating(fn (self $page) => $page->created_by_id ??= auth()->id());

        static::saving(function (self $page) {
            if ($page->isDirty('documentation')) {
                $page->documentation = SecretText::withIds($page->documentation);
            }

            if ($page->isDirty()) {
                $page->updated_by_id = auth()->id();
            }
        });

        static::deleting(fn (self $page) => $page->children()->get()->each->delete());
    }
}
