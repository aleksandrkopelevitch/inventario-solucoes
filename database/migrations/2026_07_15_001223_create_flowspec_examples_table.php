<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Curated corpus of Digibee flowSpec examples (F8). Each row is a full
 * pipeline ({meta, flowSpec}) described and tagged, selected by tags/text
 * search — no RAG/embeddings — to compose the generator's prompt.
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
