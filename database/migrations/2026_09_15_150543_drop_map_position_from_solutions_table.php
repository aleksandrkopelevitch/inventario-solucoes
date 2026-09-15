<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * The ecosystem map used to let an admin drag a solution's card and saved
 * where they dropped it, globally, for everybody. The map's layout is
 * computed now (concentric rings, or a force simulation), and it expands and
 * collapses as somebody drills into it — so there is no single arrangement
 * left for a stored `x`/`y` to describe.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('solutions', function (Blueprint $table) {
            $table->dropColumn('map_position');
        });
    }

    public function down(): void
    {
        Schema::table('solutions', function (Blueprint $table) {
            $table->json('map_position')->nullable()->after('logo_path');
        });
    }
};
