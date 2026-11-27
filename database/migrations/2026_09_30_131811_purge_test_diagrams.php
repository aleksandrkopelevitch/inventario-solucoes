<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Spatie\MediaLibrary\MediaCollections\Models\Media;

/**
 * Deletes every diagram that exists before diagrams start belonging to a
 * caderno (`add_notebook_id_to_diagrams_table`, next).
 *
 * Every one of them was a test drawing — the cadernos are the only real data
 * in this app so far, and that was the owner's call (2026-09-30) rather than a
 * guess. Deleting them is what lets the next migration add `notebook_id` as
 * NOT NULL with no backfill: there is no honest way to decide which caderno a
 * drawing nobody filed belongs to.
 *
 * The media goes through the Media MODEL, not a table delete: each diagram
 * carried Spatie media (the pasted images and the rendered picture), and only
 * a model delete removes those files. `diagram_solution` cascades and
 * `approved_topologies.diagram_id` is `nullOnDelete`, so nothing else is left
 * pointing at a row that is gone.
 *
 * It ran in production on 2026-09-30. It used to delete through the `Diagram`
 * model; that class left with the drawings (2026-11-27), so the same work is
 * written against the tables and the stored morph name, which is what lets a
 * fresh database (the test suite) still migrate.
 */
return new class extends Migration
{
    public function up(): void
    {
        Media::query()
            ->where('model_type', 'App\\Models\\Diagram')
            ->lazyById()
            ->each(fn (Media $media) => $media->delete());

        DB::table('diagrams')->delete();
    }

    /** Nothing to restore — the drawings were test data and are not coming back. */
    public function down(): void {}
};
