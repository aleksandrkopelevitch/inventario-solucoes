<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * The slide-sized version of a section.
 *
 * A section is written for a DOCUMENT — the committee reads it, so it is prose
 * with the argument in it. A slide holds a handful of short lines read from six
 * metres away. Those are two different texts, and until now the deck used the
 * document one verbatim.
 *
 * `slide_content` is that second text (Markdown, so `MarkdownToBlocks` handles
 * it exactly like the first and a human can read and fix it). `slide_source_hash`
 * is the hash of the `content` it was condensed FROM: when they disagree, the
 * section was edited afterwards and the deck falls back to the full text rather
 * than quietly printing a summary of something that no longer exists.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('submission_sections', function (Blueprint $table) {
            $table->longText('slide_content')->nullable()->after('content');
            $table->string('slide_source_hash', 32)->nullable()->after('slide_content');
        });
    }

    public function down(): void
    {
        Schema::table('submission_sections', function (Blueprint $table) {
            $table->dropColumn(['slide_content', 'slide_source_hash']);
        });
    }
};
