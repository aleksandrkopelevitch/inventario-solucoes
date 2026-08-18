<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Past approved submissions used as few-shot examples, selected per request by
 * tags (mirroring `flowspec_examples` — a couple of them, since more dilutes
 * the signal rather than adding to it).
 *
 * `sections` is the text keyed by App\Enums\SubmissionSectionKey. It is
 * harvested, not typed: an old `.pptx` is zipped XML, so the extractor that
 * ingests material also fills the corpus.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('cati_examples', function (Blueprint $table) {
            $table->id();
            $table->string('name');
            $table->string('slug')->unique();
            $table->text('summary')->nullable();
            $table->json('sections');
            $table->json('tags')->nullable();
            $table->string('source')->nullable();
            $table->boolean('is_active')->default(true);
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('cati_examples');
    }
};
