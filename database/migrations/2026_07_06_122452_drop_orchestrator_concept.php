<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Remove o conceito de "orquestrador" do sistema (decisão de 2026-07-06):
     * o papel no pivot vira intermediário, e caem as colunas
     * `integrations.orchestrator_solution_id` e `solutions.is_orchestrator`.
     * Soluções iPaaS (Digibee) seguem como participantes comuns da cadeia.
     */
    public function up(): void
    {
        // Antes de reclassificar, remove linhas 'orchestrator' que colidiriam
        // com uma linha 'intermediary' já existente da mesma (integração,
        // solução) — o índice único do pivot inclui o role.
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
