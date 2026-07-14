<?php

namespace App\Models;

use App\Contracts\Documentable;
use Database\Factories\DocumentationPageFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\MorphTo;
use Spatie\MediaLibrary\InteractsWithMedia;

/**
 * Uma página de documentação (Markdown + notação GitBook), unidade atômica
 * editada pelo bloco Editor.js — igual ao antigo blob único de
 * `Solution`/`Integration`, só que agora uma Solution (ou um
 * `DocumentationGroup` standalone) pode ter 1..N delas, em lista plana
 * ordenada por `position`. `container` é polimórfico (`Solution` ou
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
        // Ver Solution/Integration — mídia da documentação, servida por
        // `files.show`, referenciada por /files/{id} no Markdown.
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
