<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Admin-curated committee guidelines — Markdown always folded into the
 * interview's system prompt, mirroring `flowspec_guidelines`. This is half of
 * what makes the assistant "know the CATI" without any RAG; the other half is
 * `cati_examples`.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('cati_guidelines', function (Blueprint $table) {
            $table->id();
            $table->string('title');
            $table->string('slug')->unique();
            $table->longText('content');
            $table->string('source')->nullable();
            $table->boolean('is_active')->default(true);
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('cati_guidelines');
    }
};
