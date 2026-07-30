<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Admin-curated guideline documents (F8) — Markdown notes always folded into
 * FlowspecPromptBuilder::systemPrompt(), unlike the tag-selected FlowspecExample
 * corpus. One row per topic (e.g. "Boas práticas Digibee").
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('flowspec_guidelines', function (Blueprint $table) {
            $table->id();
            $table->string('title');
            $table->string('slug')->unique();
            $table->longText('content');
            $table->string('source')->default('manual');
            $table->boolean('is_active')->default(true);
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('flowspec_guidelines');
    }
};
