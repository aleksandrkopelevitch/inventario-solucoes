<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Content already migrated to `documentation_pages` (see previous
     * migration) — a Solution now has 1..N pages instead of a single blob.
     */
    public function up(): void
    {
        Schema::table('solutions', function (Blueprint $table) {
            $table->dropColumn('documentation');
        });
    }

    public function down(): void
    {
        Schema::table('solutions', function (Blueprint $table) {
            $table->longText('documentation')->nullable()->after('support_operation_note');
        });
    }
};
