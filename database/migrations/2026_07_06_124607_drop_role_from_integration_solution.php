<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Removes the role label (source/target/intermediary) from the pivot —
     * 2026-07-06 decision: it was purely derived from the chain (never
     * edited) and redundant with `integrations.source_solution_id`/
     * `target_solution_id` + the pivot's position, which already order the
     * flow. `position` alone still distinguishes the same solution
     * participating twice (e.g. SAP on both legs of the VPR flow).
     */
    public function up(): void
    {
        Schema::table('integration_solution', function (Blueprint $table) {
            $table->dropUnique(['integration_id', 'solution_id', 'role']);
            $table->dropColumn('role');
            $table->unique(['integration_id', 'solution_id', 'position']);
        });
    }

    public function down(): void
    {
        Schema::table('integration_solution', function (Blueprint $table) {
            $table->dropUnique(['integration_id', 'solution_id', 'position']);
            $table->string('role')->default('intermediary');
            $table->unique(['integration_id', 'solution_id', 'role']);
        });
    }
};
