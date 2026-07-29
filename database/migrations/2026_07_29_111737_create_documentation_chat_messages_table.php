<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Messages in a documentation_chat. `draft` carries the full proposed
 * Markdown replacement when the assistant's reply includes one (never
 * written to the target directly — the user applies it into the editor and
 * still has to Salvar); `meta` audits the generation (tokens, context docs
 * used/omitted, requirements snapshot).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('documentation_chat_messages', function (Blueprint $table) {
            $table->id();
            $table->foreignId('documentation_chat_id')->constrained()->cascadeOnDelete();
            $table->string('role'); // user | assistant
            $table->text('content');
            // The editor's live Markdown snapshot at the moment a USER message was
            // sent (may include unsaved edits, same reasoning the old one-shot flow
            // had) — the "before" side the generation actually runs against; null on
            // assistant messages.
            $table->longText('existing_content')->nullable();
            $table->longText('draft')->nullable();
            $table->json('context_media_ids')->nullable();
            $table->json('meta')->nullable();
            $table->timestamp('applied_at')->nullable();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('documentation_chat_messages');
    }
};
