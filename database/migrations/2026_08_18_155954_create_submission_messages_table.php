<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Messages in a submission_chat.
 *
 * `drafts` is a json LIST of `{key, markdown}` — the deliberate divergence
 * from `documentation_chat_messages.draft` (a single text column): there the
 * reply proposes one whole page, here a single turn can legitimately propose
 * content for more than one section at once ("isso responde o Resumo e parte
 * dos Objetivos").
 *
 * `applied_at` marks that the user accepted the drafts of this message.
 * Unlike the documentation assistant — where applying only pushes Markdown
 * into a client-side editor and is pure bookkeeping — applying here WRITES
 * the sections server-side, leaving them in `drafted` until a human confirms.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('submission_messages', function (Blueprint $table) {
            $table->id();
            $table->foreignId('submission_chat_id')->constrained()->cascadeOnDelete();
            $table->string('role'); // user | assistant
            $table->text('content');
            $table->json('drafts')->nullable();
            // Which submission_sources were in the prompt for this turn —
            // part of the provenance trail a reviewer needs.
            $table->json('source_ids')->nullable();
            $table->json('meta')->nullable();
            $table->timestamp('applied_at')->nullable();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('submission_messages');
    }
};
