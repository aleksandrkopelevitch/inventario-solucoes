<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Corpus curado de exemplos de flowSpec Digibee (F8). Cada linha é um pipeline
 * completo ({meta, flowSpec}) descrito e tagueado, selecionado por tags/busca
 * textual — sem RAG/embeddings — para compor o prompt do gerador.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('flowspec_examples', function (Blueprint $table) {
            $table->id();
            $table->string('name');
            $table->string('slug')->unique();
            $table->text('description');
            $table->jsonb('tags');
            $table->jsonb('flow_spec');
            $table->jsonb('connectors');
            $table->string('source')->default('manual');
            $table->boolean('is_active')->default(true);
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('flowspec_examples');
    }
};
