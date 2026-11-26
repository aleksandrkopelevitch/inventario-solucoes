<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Interconnections between two catalog solutions that a PERSON records — on
 * the solution page, inline, or through the MCP — beside the ones the
 * documentation's UML diagrams state (`uml_solution_links`). One per pair: a
 * manual record also OVERRIDES what the documentation says about that pair,
 * and removing it brings the documented answer back
 * (docs-and-specs/solution-relationships-from-uml.md §5.2).
 *
 * The pair is stored NORMALIZED — `solution_a_id` is always the lower id — so
 * a plain unique index keeps it to one row whichever side it was entered
 * from; a LEAST/GREATEST expression index would be spelled differently by
 * SQLite (the tests) and Postgres. `direction` is read from A's side.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('solution_relationships', function (Blueprint $table) {
            $table->id();
            $table->foreignId('solution_a_id')->constrained('solutions')->cascadeOnDelete();
            $table->foreignId('solution_b_id')->constrained('solutions')->cascadeOnDelete();
            $table->string('direction', 10);
            $table->foreignId('created_by_id')->nullable()->constrained('users')->nullOnDelete();
            $table->foreignId('updated_by_id')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();

            $table->unique(['solution_a_id', 'solution_b_id']);
            $table->index('solution_b_id');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('solution_relationships');
    }
};
