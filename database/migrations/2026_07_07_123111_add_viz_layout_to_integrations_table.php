<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Visual layout of the F3 graphical viz (block positions + arrow endpoint
 * anchors), saved per integration. It's PURELY visual — it doesn't derive
 * topology (the `chain` remains the source of truth). Its own column instead
 * of reusing the orphaned `diagram` (from the removed canvas editor) to avoid
 * confusing semantics.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('integrations', function (Blueprint $table) {
            $table->json('viz_layout')->nullable()->after('chain');
        });
    }

    public function down(): void
    {
        Schema::table('integrations', function (Blueprint $table) {
            $table->dropColumn('viz_layout');
        });
    }
};
