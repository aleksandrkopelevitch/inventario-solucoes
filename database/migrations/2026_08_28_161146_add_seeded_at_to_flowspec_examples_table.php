<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Marks which corpus examples the SEEDER owns, so it can deactivate the ones
 * that leave the manifest without touching the ones an admin created in the app.
 *
 * `source` cannot answer that question, which is the whole reason this column
 * exists: 4 manifest entries carry `source: 'chat'` (promoted from a
 * conversation, then curated into the manifest), while `SaveFlowspecExample`
 * — the app's own creator — writes `source: 'manual'`. Both values appear on
 * both sides of the line, so ownership needed a marker of its own.
 *
 * Deliberately NOT in `$fillable`: the seeder force-fills it, exactly like
 * `parent_id`/`notebook_id` elsewhere, so no request can ever claim an
 * app-created example is seeder-owned and get it auto-deactivated.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('flowspec_examples', function (Blueprint $table) {
            $table->timestamp('seeded_at')->nullable()->after('source');
        });
    }

    public function down(): void
    {
        Schema::table('flowspec_examples', function (Blueprint $table) {
            $table->dropColumn('seeded_at');
        });
    }
};
