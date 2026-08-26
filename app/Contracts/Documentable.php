<?php

namespace App\Contracts;

use Spatie\MediaLibrary\HasMedia;

/**
 * A model that carries rich documentation (`documentation` column, Markdown +
 * GitBook-style extended notation) and media embedded in it (Spatie
 * MediaLibrary's `docs` collection, referenced by `/files/{id}` inside the text).
 *
 * Implemented by DocumentationPage and nothing else — it is the one kind of
 * documentation there is. `Integration` used to implement it too, with a
 * `documentation` column of its own; that entity became `Diagram`, which holds
 * a drawing and no prose. Consumed by the block editor (Editor.js) via
 * App\Http\Controllers\Concerns\EditsDocumentation and by the read-only
 * render App\Support\GitbookRenderer.
 *
 * `DOCS_COLLECTION` still has readers beyond that one implementer, and
 * deliberately so: `MediaController::show()` authorizes `/files/{id}` by this
 * collection name alone, so every canvas owner (`Diagram`,
 * `SubmissionDiagram`) stores its pasted image nodes in a collection with
 * exactly this name in order to be servable at all.
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
