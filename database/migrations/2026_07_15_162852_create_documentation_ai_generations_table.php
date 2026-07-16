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
        Schema::create('documentation_ai_generations', function (Blueprint $table) {
            $table->id();
            // Alvo da geração: uma DocumentationPage ou uma Integration.
            $table->morphs('target');
            // Solução dona dos documentos de contexto usados na geração.
            $table->foreignId('solution_id')->constrained()->cascadeOnDelete();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->string('status')->default('pending'); // pending | completed | failed
            $table->text('prompt');
            $table->json('context_media_ids')->nullable();
            $table->longText('existing_content')->nullable();
            $table->longText('result')->nullable();
            $table->text('error')->nullable();
            $table->json('meta')->nullable();
            $table->timestamps();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('documentation_ai_generations');
    }
};
