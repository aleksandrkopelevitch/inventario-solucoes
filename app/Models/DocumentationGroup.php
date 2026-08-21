<?php

namespace App\Models;

use Database\Factories\DocumentationGroupFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\MorphMany;

/**
 * A nesting of documentation pages that doesn't belong to any Solution —
 * standalone, for "loose" docs (e.g. a cross-cutting process). Same page
 * tree (`DocumentationPage`, `documentation` column) as a Solution, via the
 * polymorphic `container`.
 */
class DocumentationGroup extends Model
{
    /** @use HasFactory<DocumentationGroupFactory> */
    use HasFactory;

    protected $fillable = [
        'name',
        'slug',
    ];

    public function getRouteKeyName(): string
    {
        return 'slug';
    }

    public function pages(): MorphMany
    {
        return $this->morphMany(DocumentationPage::class, 'container')->orderBy('position');
    }

    /**
     * Only pages with actual content — the same scope Solution carries, and for
     * the same reason: a page with an empty body is a heading with nothing
     * under it wherever documentation is listed to be chosen from (the flowSpec
     * context picker) or counted as coverage.
     */
    public function documentedPages(): MorphMany
    {
        return $this->pages()->whereNotNull('documentation')->where('documentation', '<>', '');
    }

    /**
     * No real FK to cascade (container is polymorphic) — deletes each page
     * through its own model, so it also triggers media cleanup (Spatie hooks
     * into DocumentationPage's `deleting`).
     */
    protected static function booted(): void
    {
        static::deleting(fn (self $group) => $group->pages->each->delete());
    }
}
