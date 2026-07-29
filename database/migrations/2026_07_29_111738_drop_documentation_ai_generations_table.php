<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Retires the one-shot "Assiste IA" generation flow, replaced by the
 * documentation_chats/documentation_chat_messages conversation (see
 * App\Models\DocumentationChat) — DocumentationAiGeneration, its job and
 * service are deleted alongside this migration.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::dropIfExists('documentation_ai_generations');
    }

    public function down(): void
    {
        Schema::create('documentation_ai_generations', function (Blueprint $table) {
            $table->id();
            $table->morphs('target');
            $table->foreignId('solution_id')->constrained()->cascadeOnDelete();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->string('status')->default('pending');
            $table->text('prompt');
            $table->json('context_media_ids')->nullable();
            $table->longText('existing_content')->nullable();
            $table->longText('result')->nullable();
            $table->text('error')->nullable();
            $table->json('meta')->nullable();
            $table->timestamp('consumed_at')->nullable();
            $table->timestamps();
        });
    }
};
