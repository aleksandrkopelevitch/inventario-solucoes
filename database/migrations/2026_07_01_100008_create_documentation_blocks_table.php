<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('documentation_blocks', function (Blueprint $table) {
            $table->id();
            $table->foreignId('integration_id')->constrained('integrations')->cascadeOnDelete();
            $table->string('type'); // DocumentationBlockType enum
            $table->integer('position');
            $table->jsonb('content'); // payload por tipo (secao 11.2)
            $table->timestamps();

            $table->index(['integration_id', 'position']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('documentation_blocks');
    }
};
