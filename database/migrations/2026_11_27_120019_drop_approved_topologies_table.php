<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * An approval no longer records a topology to apply into a drawing: who
     * talks to whom is the documentation's now (`uml_solution_links`,
     * `solution_relationships`). Before `diagrams`, which it references.
     *
     * `down()` gives back the empty table, so a rollback to the previous
     * release finds the schema it expects; the rows are not restored.
     */
    public function up(): void
    {
        Schema::dropIfExists('approved_topologies');
    }

    public function down(): void
    {
        Schema::create('approved_topologies', function (Blueprint $table) {
            $table->id();
            $table->foreignId('submission_id')->unique()->constrained()->cascadeOnDelete();
            $table->foreignId('solution_id')->constrained()->cascadeOnDelete();
            $table->json('chain');
            $table->json('viz_layout')->nullable();
            $table->timestamp('approved_at');
            $table->foreignId('diagram_id')->nullable()->constrained()->nullOnDelete();
            $table->timestamp('applied_at')->nullable();
            $table->foreignId('applied_by_id')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('dismissed_at')->nullable();
            $table->foreignId('dismissed_by_id')->nullable()->constrained('users')->nullOnDelete();
            $table->string('dismissed_reason')->nullable();
            $table->timestamps();

            $table->index(['solution_id', 'applied_at', 'dismissed_at']);
        });
    }
};
