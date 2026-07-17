<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Removes F6 (free-form architecture diagrams, canvas authoring mode)
     * entirely — 2026-07-06 decision: the integration diagram editor
     * (`integrations.diagram`) draws flows in the new standard and made F6
     * redundant. `flow_solution` before `flows` because of the FK.
     */
    public function up(): void
    {
        Schema::dropIfExists('flow_solution');
        Schema::dropIfExists('flows');
    }

    public function down(): void
    {
        Schema::create('flows', function (Blueprint $table) {
            $table->id();
            $table->string('name');
            $table->string('slug')->unique();
            $table->text('description')->nullable();
            $table->json('flow_json')->default('{}');
            $table->timestamps();
        });

        Schema::create('flow_solution', function (Blueprint $table) {
            $table->foreignId('flow_id')->constrained()->cascadeOnDelete();
            $table->foreignId('solution_id')->constrained()->cascadeOnDelete();
            $table->timestamps();

            $table->unique(['flow_id', 'solution_id']);
        });
    }
};
