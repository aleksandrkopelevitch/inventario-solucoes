<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * The Documentation Assistant ("Assiste IA") conversation for one user about
 * one target (a DocumentationPage or an Integration) — one ongoing chat per
 * (user, target), so reopening the panel resumes the same thread instead of
 * starting a new one.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('documentation_chats', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->morphs('target');
            // Solution owning the context documents used in the conversation.
            $table->foreignId('solution_id')->nullable()->constrained()->nullOnDelete();
            $table->timestamps();

            $table->unique(['user_id', 'target_type', 'target_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('documentation_chats');
    }
};
