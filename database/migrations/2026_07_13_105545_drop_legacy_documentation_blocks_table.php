<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * A tabela `documentation_blocks` (schema legado da F4 removida) nunca
     * teve model, controller ou UI vinculados — a documentação rica agora vive
     * na coluna `documentation` (Markdown + notação GitBook) de `solutions` e
     * `integrations`. Removida para não deixar schema órfão.
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
