<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * The adaptive interview for one submission, per user — one ongoing chat per
 * (user, submission): reopening the panel resumes the same thread instead of
 * starting a new one, same contract as `documentation_chats`.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('submission_chats', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->constrained();
            $table->foreignId('submission_id')->constrained()->cascadeOnDelete();
            $table->timestamps();

            $table->unique(['user_id', 'submission_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('submission_chats');
    }
};
