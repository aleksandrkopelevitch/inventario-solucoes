<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Grafo do editor visual de integrações: {nodes, edges, clouds}. Nota
     * histórica: nasceu como fonte de verdade da topologia (participants/
     * source/target/direction derivados dele); desde a coluna `chain`
     * (2026-07-06) é só o desenho macro — a derivação vem da cadeia
     * (App\Actions\SyncIntegrationFromChain). jsonb para paridade com
     * generated_flowspec e consultas futuras.
     */
    public function up(): void
    {
        Schema::table('integrations', function (Blueprint $table) {
            $table->jsonb('diagram')->nullable()->after('criticality');
        });
    }

    public function down(): void
    {
        Schema::table('integrations', function (Blueprint $table) {
            $table->dropColumn('diagram');
        });
    }
};
