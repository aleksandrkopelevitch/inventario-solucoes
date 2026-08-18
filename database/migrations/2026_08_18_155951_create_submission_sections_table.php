<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * One row per section of a submission (App\Enums\SubmissionSectionKey) — the
 * six the Leo Resolve form requires, plus Alternativas, plus the four the deck
 * template asks for and the form doesn't. One list serves both renderings.
 *
 * `state` is what separates "the assistant proposed this" from "a human signed
 * it" (App\Enums\SubmissionSectionState): the ticket's final checklist is
 * derived from it, so nobody ticks a box by hand. `provenance` records where
 * the content came from (which source file/slide, which inventory field, which
 * chat message) — a generated document is only trustworthy while a reviewer
 * can see that.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('submission_sections', function (Blueprint $table) {
            $table->id();
            $table->foreignId('submission_id')->constrained()->cascadeOnDelete();
            $table->string('key');
            $table->longText('content')->nullable();
            $table->string('state')->default('empty');
            $table->json('provenance')->nullable();
            $table->foreignId('updated_by_id')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();

            // One row per (submission, section). Submission::section() relies
            // on this to make its firstOrCreate safe under concurrency.
            $table->unique(['submission_id', 'key']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('submission_sections');
    }
};
