<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('integration_solution', function (Blueprint $table) {
            $table->foreignId('integration_id')->constrained('integrations')->cascadeOnDelete();
            $table->foreignId('solution_id')->constrained('solutions')->cascadeOnDelete();
            $table->string('role'); // IntegrationParticipantRole enum: source|target|intermediary|orchestrator
            $table->integer('position'); // ordem no fluxo
            $table->timestamps();

            $table->index(['integration_id', 'position']);
            $table->unique(['integration_id', 'solution_id', 'role']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('integration_solution');
    }
};
