<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Removes the "orchestrator" concept from the system (2026-07-06
     * decision): the pivot role becomes intermediary, and the
     * `integrations.orchestrator_solution_id` and `solutions.is_orchestrator`
     * columns are dropped. iPaaS solutions (Digibee) remain regular chain
     * participants.
     */
    public function up(): void
    {
        // Before reclassifying, remove 'orchestrator' rows that would collide
        // with an already-existing 'intermediary' row for the same
        // (integration, solution) — the pivot's unique index includes role.
        DB::table('integration_solution')
            ->where('role', 'intermediary')
            ->get(['integration_id', 'solution_id'])
            ->each(fn ($row) => DB::table('integration_solution')
                ->where('role', 'orchestrator')
                ->where('integration_id', $row->integration_id)
                ->where('solution_id', $row->solution_id)
                ->delete());

        DB::table('integration_solution')
            ->where('role', 'orchestrator')
            ->update(['role' => 'intermediary']);

        Schema::table('integrations', function (Blueprint $table) {
            $table->dropConstrainedForeignId('orchestrator_solution_id');
        });

        Schema::table('solutions', function (Blueprint $table) {
            $table->dropColumn('is_orchestrator');
        });
    }

    public function down(): void
    {
        Schema::table('integrations', function (Blueprint $table) {
            $table->foreignId('orchestrator_solution_id')->nullable()->constrained('solutions')->nullOnDelete();
        });

        Schema::table('solutions', function (Blueprint $table) {
            $table->boolean('is_orchestrator')->default(false);
        });
    }
};
