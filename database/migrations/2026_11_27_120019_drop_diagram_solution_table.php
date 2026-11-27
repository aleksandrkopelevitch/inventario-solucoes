<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * The drawings' participants. Before `diagrams`, which it references.
     * `down()` gives back the empty table for a rollback; the rows are not
     * restored.
     */
    public function up(): void
    {
        Schema::dropIfExists('diagram_solution');
    }

    public function down(): void
    {
        Schema::create('diagram_solution', function (Blueprint $table) {
            $table->foreignId('diagram_id')->constrained()->cascadeOnDelete();
            $table->foreignId('solution_id')->index()->constrained()->cascadeOnDelete();
            $table->unsignedInteger('position');
            $table->boolean('manual')->default(false);
            $table->timestamps();

            $table->index(['diagram_id', 'position']);
            $table->unique(['diagram_id', 'solution_id', 'position']);
        });
    }
};
