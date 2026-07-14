<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('integrations', function (Blueprint $table) {
            $table->jsonb('generated_flowspec')->nullable()->after('criticality');
            $table->string('flowspec_status')->default('idle')->after('generated_flowspec');
            $table->timestamp('flowspec_generated_at')->nullable()->after('flowspec_status');
        });
    }

    public function down(): void
    {
        Schema::table('integrations', function (Blueprint $table) {
            $table->dropColumn(['generated_flowspec', 'flowspec_status', 'flowspec_generated_at']);
        });
    }
};
