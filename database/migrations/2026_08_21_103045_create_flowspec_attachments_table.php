<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * The context attached to one Especialista em Integrações conversation.
 *
 * Deliberately CHAT-scoped, not message-scoped: what you attach stays in the
 * conversation and is re-sent on every following turn, which is the mental
 * model of a Claude project. The per-message `meta.solution_ids` /
 * `meta.document_refs` / `meta.reference_flowspec` this replaces were reset
 * after each send, so the second question in a thread silently lost the
 * documentation the first one was answered with.
 *
 * Exactly two kinds of context exist, which is the whole point of this table:
 *
 * - `document` — a REFERENCE to something already in the inventory (a
 *   DocumentationPage or an Integration's own documentation), carried in
 *   `reference_type`/`reference_id`. The text is never copied: the page is
 *   read live at generation time, so editing the documentation updates every
 *   conversation pointing at it instead of leaving stale snapshots behind.
 * - `file` / `text` — material the user brought: an upload (media in the
 *   `flowspec_attachments` collection) or long text pasted into the composer.
 *   Here the text IS copied into `content`, because there is no other copy of
 *   it anywhere.
 *
 * `extraction_state` mirrors `submission_sources`: a PDF or an image is
 * `skipped`, NOT failed — it rides along as a native attachment
 * (Laravel\Ai\Files\LocalDocument / LocalImage) instead of being inlined as
 * text. See App\Enums\ContextExtractionState.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('flowspec_attachments', function (Blueprint $table) {
            $table->id();
            // Explicitly indexed: `constrained()` creates the FK but PostgreSQL
            // (unlike MySQL) does not index the referencing column on its own,
            // and every read of a conversation's context filters on it. Same
            // omission a past migration had to come back and fix for the other
            // flowspec tables.
            $table->foreignId('flowspec_chat_id')->constrained()->cascadeOnDelete()->index();
            $table->string('kind'); // document | file | text
            $table->string('label');
            // Only for kind=document — the inventory record read live.
            $table->nullableMorphs('reference');
            // Only for kind=file. nullOnDelete keeps the row (and its extracted
            // text) auditable if the media is ever deleted out from under it.
            $table->foreignId('media_id')->nullable()->constrained('media')->nullOnDelete();
            // Inlined text for kind=file (extracted) and kind=text (pasted).
            // Always null for kind=document, which is read from its reference.
            $table->longText('content')->nullable();
            $table->string('extraction_state')->default('done');
            $table->string('extraction_note')->nullable();
            // Likely credentials spotted in `content`. Flagged, never removed
            // — see App\Support\Context\SensitiveTextScanner.
            $table->json('sensitive_findings')->nullable();
            // A pasted `{meta, flowSpec}` document, recognized at paste time and
            // minified by NormalizeReferenceFlowspec. It gets its own prompt
            // section ("flowSpec de referência") instead of being dumped in with
            // the prose material — the old standalone reference-flowSpec editor
            // survives as this flag, not as a third attachment type.
            $table->boolean('is_flowspec_reference')->default(false);
            // Snapshot of what this attachment costs the context window, so the
            // meter is one cheap query. Only meaningful for file/text, whose
            // content is immutable once stored; a `document` row is measured
            // live against its reference (see FlowspecContextBudget).
            $table->unsignedInteger('token_estimate')->default(0);
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('flowspec_attachments');
    }
};
