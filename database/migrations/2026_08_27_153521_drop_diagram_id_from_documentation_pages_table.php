<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * A drawing and the text about it stop being wired together in the database.
 *
 * `documentation_pages.diagram_id` was what replaced the retired integration's
 * own `documentation` column: one page, one diagram, and a page that had one
 * grew a Documentação/Diagrama tab pair around the canvas. It always modelled
 * something narrower than what people do — a page cites SEVERAL drawings far
 * more often than it is "the page of" exactly one — and it made the diagram
 * catalog answer a question it had no business answering ("which page explains
 * me?").
 *
 * A diagram is reached from prose the way any other referenced thing is: by
 * being CITED in it, as a `{% diagram %}` block (see GitbookRenderer). A
 * citation is content, not schema — a page can carry five, in any order, next
 * to the paragraph that needs them.
 *
 * What survives is the relation that was never about documentation: a diagram's
 * `participants`, derived from its own chain by `SyncDiagramFromChain`. That is
 * still the only link between a drawing and the catalog, and it is still a
 * reading of the drawing rather than of somebody's filing.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('documentation_pages', function (Blueprint $table) {
            $table->dropConstrainedForeignId('diagram_id');
        });
    }

    public function down(): void
    {
        Schema::table('documentation_pages', function (Blueprint $table) {
            $table->foreignId('diagram_id')->nullable()->constrained()->nullOnDelete();
        });
    }
};
