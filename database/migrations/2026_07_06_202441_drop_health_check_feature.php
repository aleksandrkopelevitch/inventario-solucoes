<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Remove por completo a feature de health-check (F9): a tabela de pipeline e a
 * coluna `integrations.health_check_url` que só existia para alimentá-la. O
 * catálogo/mapa de integrações passou a viver a partir da solução e o
 * health-check foi descontinuado.
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
