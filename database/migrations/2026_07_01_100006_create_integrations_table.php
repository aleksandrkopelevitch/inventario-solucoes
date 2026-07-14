<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('integrations', function (Blueprint $table) {
            $table->id();
            $table->string('name'); // ex: "CWS <-> SAP S/4HANA"
            $table->string('slug')->unique();
            $table->foreignId('source_solution_id')->constrained('solutions')->cascadeOnDelete();
            $table->foreignId('target_solution_id')->constrained('solutions')->cascadeOnDelete();
            $table->foreignId('orchestrator_solution_id')->nullable()->constrained('solutions')->nullOnDelete();
            $table->string('direction'); // Direction enum: unidirectional|bidirectional
            $table->string('protocol')->nullable(); // Protocol enum
            $table->string('sync_mode')->nullable(); // SyncMode enum
            $table->string('status'); // IntegrationStatus enum
            $table->string('criticality'); // Criticality enum
            $table->timestamps();

            $table->index(['source_solution_id', 'status']);
            $table->index(['target_solution_id', 'status']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('integrations');
    }
};
