<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Graph for the visual integration editor: {nodes, edges, clouds}.
     * Historical note: it started as the topology source of truth
     * (participants/source/target/direction derived from it); since the
     * `chain` column (2026-07-06) it's just the macro drawing — derivation
     * comes from the chain (App\Actions\SyncIntegrationFromChain). jsonb for
     * parity with generated_flowspec and future queries.
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
