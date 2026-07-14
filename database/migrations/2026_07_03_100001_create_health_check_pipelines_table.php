<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Linha única (F9, Apêndice B) para a última tentativa de geração da
 * pipeline de health-check — não pertence a uma integração específica, por
 * isso não reaproveita as colunas `flowspec_*` de `integrations` (F8).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('health_check_pipelines', function (Blueprint $table) {
            $table->id();
            $table->jsonb('generated_flowspec')->nullable();
            $table->string('flowspec_status')->default('idle');
            $table->timestamp('flowspec_generated_at')->nullable();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('health_check_pipelines');
    }
};
