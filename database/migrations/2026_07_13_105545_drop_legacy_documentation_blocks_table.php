<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * The `documentation_blocks` table (legacy schema from the removed F4)
     * never had a model, controller, or UI attached to it — rich
     * documentation now lives in the `documentation` column (Markdown +
     * GitBook notation) of `solutions` and `integrations`. Dropped to avoid
     * leaving an orphaned schema.
     */
    public function up(): void
    {
        Schema::dropIfExists('documentation_blocks');
    }

    public function down(): void
    {
        Schema::create('documentation_blocks', function (Blueprint $table) {
            $table->id();
            $table->foreignId('integration_id')->constrained('integrations')->cascadeOnDelete();
            $table->string('type');
            $table->integer('position');
            $table->jsonb('content');
            $table->timestamps();

            $table->index(['integration_id', 'position']);
        });
    }
};
