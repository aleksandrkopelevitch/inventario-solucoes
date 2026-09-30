<?php

use App\Models\Diagram;
use Illuminate\Database\Migrations\Migration;

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
 * Through the MODELS, not a table truncate: each diagram carries Spatie media
 * (the pasted images and the rendered picture), and only a model delete
 * removes those files. `diagram_solution` cascades and
 * `approved_topologies.diagram_id` is `nullOnDelete`, so nothing else is left
 * pointing at a row that is gone.
 */
return new class extends Migration
{
    public function up(): void
    {
        Diagram::query()->cursor()->each(fn (Diagram $diagram) => $diagram->delete());
    }

    /** Nothing to restore — the drawings were test data and are not coming back. */
    public function down(): void {}
};
