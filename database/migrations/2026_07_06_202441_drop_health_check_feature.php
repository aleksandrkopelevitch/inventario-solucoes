<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Removes the health-check feature (F9) entirely: the pipeline table and the
 * `integrations.health_check_url` column that only existed to feed it. The
 * integration catalog/map now lives off the solution and health-check was
 * discontinued.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::dropIfExists('health_check_pipelines');

        Schema::table('integrations', function (Blueprint $table) {
            $table->dropColumn('health_check_url');
        });
    }

    public function down(): void
    {
        Schema::table('integrations', function (Blueprint $table) {
            $table->string('health_check_url')->nullable()->after('criticality');
        });

        Schema::create('health_check_pipelines', function (Blueprint $table) {
            $table->id();
            $table->jsonb('generated_flowspec')->nullable();
            $table->string('flowspec_status')->default('idle');
            $table->timestamp('flowspec_generated_at')->nullable();
            $table->timestamps();
        });
    }
};
