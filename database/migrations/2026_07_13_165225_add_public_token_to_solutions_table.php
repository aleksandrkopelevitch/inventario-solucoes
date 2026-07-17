<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::table('solutions', function (Blueprint $table) {
            // Public documentation link token ("magic link"). Null = doc not
            // shared; regenerating the token revokes the old link.
            $table->string('public_token', 64)->nullable()->unique()->after('slug');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('solutions', function (Blueprint $table) {
            $table->dropColumn('public_token');
        });
    }
};
