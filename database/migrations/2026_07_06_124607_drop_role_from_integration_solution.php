<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Remove o rótulo de papel (origem/destino/intermediário) do pivot —
     * decisão de 2026-07-06: era puramente derivado da cadeia (nunca editado)
     * e redundante com `integrations.source_solution_id`/`target_solution_id`
     * + a posição no pivot, que já ordenam o fluxo. `position` sozinha segue
     * distinguindo uma mesma solução participando duas vezes (ex.: SAP em
     * ida e volta no fluxo VPR).
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
