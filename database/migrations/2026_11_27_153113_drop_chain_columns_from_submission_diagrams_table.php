<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * The canvas's topology and layout. Every AS IS / TO BE is an uploaded
     * picture now (previous migration), so nothing reads them. `down()` gives
     * the columns back empty, for a rollback to the release that drew them.
     */
    public function up(): void
    {
        Schema::table('submission_diagrams', function (Blueprint $table) {
            $table->dropColumn(['chain', 'viz_layout']);
        });
    }

    public function down(): void
    {
        Schema::table('submission_diagrams', function (Blueprint $table) {
            $table->json('chain')->nullable();
            $table->json('viz_layout')->nullable();
        });
    }
};
