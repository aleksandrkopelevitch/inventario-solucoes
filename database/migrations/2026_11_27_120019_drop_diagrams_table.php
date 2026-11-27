<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * The topology drawings themselves (`/diagrams`). Every one of them was
     * converted into a `{% uml %}` block in its caderno on 2026-11-26
     * (`diagrams:to-uml`), and the relationships they stated are read from
     * the documentation now (docs-and-specs/solution-relationships-from-uml.md).
     *
     * `down()` gives back the empty table for a rollback; the rows are not
     * restored.
     */
    public function up(): void
    {
        Schema::dropIfExists('diagrams');
    }

    public function down(): void
    {
        Schema::create('diagrams', function (Blueprint $table) {
            $table->id();
            $table->string('name');
            $table->string('slug')->unique();
            $table->foreignId('notebook_id')->index()->constrained()->cascadeOnDelete();
            $table->foreignId('source_solution_id')->nullable()->constrained('solutions')->cascadeOnDelete();
            $table->foreignId('target_solution_id')->nullable()->constrained('solutions')->cascadeOnDelete();
            $table->string('direction');
            $table->string('protocol')->nullable();
            $table->string('sync_mode')->nullable();
            $table->string('status');
            $table->string('criticality');
            $table->json('chain')->nullable();
            $table->json('viz_layout')->nullable();
            $table->timestamps();

            $table->index(['source_solution_id', 'status']);
            $table->index(['target_solution_id', 'status']);
        });
    }
};
