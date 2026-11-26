<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * What the documentation's UML diagrams say about who talks to whom: one row
 * per call (or plain association) between two catalog solutions, as stated by
 * one `{% uml %}` block of one page (UmlSolutionBindings). Rebuilt for a page
 * on every save of its body (SyncPageSolutionLinks), so it is derived data:
 * it cascades away with the page or either solution, and
 * `documentation:solution-links` rebuilds all of it.
 *
 * Rows are EVIDENCE, not pairs — SolutionRelationshipService folds them per
 * pair on read, and needs `diagram_position` + `ordinal` to infer a call that
 * crosses an integration platform inside one diagram.
 *
 * The three foreign keys get explicit indexes: Postgres never creates one for
 * a constraint (see 2026_11_12_171631).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('uml_solution_links', function (Blueprint $table) {
            $table->id();
            $table->foreignId('documentation_page_id')->constrained()->cascadeOnDelete();
            $table->foreignId('from_solution_id')->constrained('solutions')->cascadeOnDelete();
            $table->foreignId('to_solution_id')->constrained('solutions')->cascadeOnDelete();
            $table->boolean('directed');
            $table->unsignedSmallInteger('diagram_position');
            $table->unsignedSmallInteger('ordinal');
            $table->string('diagram_type', 20);
            $table->string('diagram_title', 120)->nullable();
            $table->string('label', 80)->nullable();

            $table->index('documentation_page_id');
            $table->index('from_solution_id');
            $table->index('to_solution_id');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('uml_solution_links');
    }
};
