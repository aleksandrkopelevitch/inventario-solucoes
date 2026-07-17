<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Single row (F9, Appendix B) for the latest health-check pipeline
 * generation attempt — doesn't belong to a specific integration, so it
 * doesn't reuse the `flowspec_*` columns from `integrations` (F8).
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
