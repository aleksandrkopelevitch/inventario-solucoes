<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('integrations', function (Blueprint $table) {
            $table->string('health_check_url')->nullable()->after('criticality');
        });
    }

    public function down(): void
    {
        Schema::table('integrations', function (Blueprint $table) {
            $table->dropColumn('health_check_url');
        });
    }
};
