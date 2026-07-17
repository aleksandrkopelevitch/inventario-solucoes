<?php

namespace App\Models;

use App\Contracts\Documentable;
use Database\Factories\DocumentationPageFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\MorphTo;
use Spatie\MediaLibrary\InteractsWithMedia;

/**
 * A documentation page (Markdown + GitBook notation), the atomic unit
 * edited by the Editor.js block — same as the old single blob on
 * `Solution`/`Integration`, except now a Solution (or a standalone
 * `DocumentationGroup`) can have 1..N of them, in a flat list ordered by
 * `position`. `container` is polymorphic (`Solution` or
 * `DocumentationGroup`).
 */
class DocumentationPage extends Model implements Documentable
{
    /** @use HasFactory<DocumentationPageFactory> */
    use HasFactory, InteractsWithMedia;

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
}
