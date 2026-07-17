<?php

namespace App\Contracts;

use Spatie\MediaLibrary\HasMedia;

/**
 * A model that carries rich documentation (`documentation` column, Markdown +
 * GitBook-style extended notation) and media embedded in it (Spatie
 * MediaLibrary's `docs` collection, referenced by `/files/{id}` inside the text).
 *
 * Implemented by DocumentationPage (the page unit, owning the documentation
 * of a Solution or of a DocumentationGroup) and by Integration (which stays
 * single-page). Consumed by the block editor (Editor.js) via
 * App\Http\Controllers\Concerns\EditsDocumentation and by the read-only
 * render App\Support\GitbookRenderer.
 *
 * @property string|null $documentation
 */
interface Documentable extends HasMedia
{
    /** Media collection where the documentation's images/files are stored. */
    public const DOCS_COLLECTION = 'docs';

    /** Human-readable label of the resource, for the editor page title. */
    public function documentationTitle(): string;
}
