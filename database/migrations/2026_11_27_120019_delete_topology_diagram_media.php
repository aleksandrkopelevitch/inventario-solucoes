<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Spatie\MediaLibrary\MediaCollections\Models\Media;

return new class extends Migration
{
    /**
     * The first step of removing the topology drawings (`/diagrams`,
     * docs-and-specs/solution-relationships-from-uml.md phase 4): their media,
     * after making sure no page still cites one.
     *
     * The GATE comes first and refuses the whole deploy. `{% diagram %}` left
     * the dialect with this release: a page still carrying one would show the
     * line as literal text, and the drawing it named is about to be dropped
     * with no way back. `diagrams:to-uml` converted every citation in
     * production on 2026-11-26 and the last one was replaced by hand, so this
     * is expected to find nothing — when it does, it names the pages.
     *
     * Then the media of the `App\Models\Diagram` rows — the rendered PNG
     * (`diagram` collection) and the pictures pasted onto the canvas (`docs`)
     * — deleted through the Media MODEL, so Spatie removes the files with the
     * rows. A bare `delete()` on the table would leave them on disk forever.
     * The class itself is gone; the stored morph name is all that is needed.
     */
    public function up(): void
    {
        $citing = DB::table('documentation_pages')
            ->join('notebooks', 'notebooks.id', '=', 'documentation_pages.notebook_id')
            ->where('documentation_pages.documentation', 'like', '%diagram%slug=%')
            ->orderBy('documentation_pages.id')
            ->get(['documentation_pages.id', 'documentation_pages.slug', 'documentation_pages.documentation', 'notebooks.slug as notebook'])
            ->filter(fn (object $page) => preg_match('/^\s*\{%\s*diagram\s+slug="[^"]*"\s*%\}\s*$/m', (string) $page->documentation))
            ->map(fn (object $page) => "{$page->notebook}/{$page->slug}");

        if ($citing->isNotEmpty()) {
            throw new RuntimeException(
                'These documentation pages still cite a topology diagram with {% diagram %}; '
                . 'convert or remove each citation before deploying: ' . $citing->implode(', '),
            );
        }

        Media::query()
            ->where('model_type', 'App\\Models\\Diagram')
            ->lazyById()
            ->each(fn (Media $media) => $media->delete());
    }

    /** The files are gone with the rows; there is nothing to put back. */
    public function down(): void {}
};
