<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Digibee web sessions captured by the server's remote sign-in ("Sessão
 * Digibee"): the two headers the Monitor screen sends, encrypted, and who
 * signed in to produce them. One row per capture; the newest unexpired one is
 * the session the server reads with.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('digibee_monitor_sessions', function (Blueprint $table) {
            $table->id();
            // Whose Digibee identity the headers carry. Null once that account
            // is gone; the row stays, since the headers still say who they are.
            $table->foreignId('user_id')->nullable()->constrained()->nullOnDelete();
            // Ciphertext (the `encrypted` cast) — longer than any varchar.
            $table->text('authorization');
            $table->text('apikey');
            $table->timestamp('expires_at')->nullable()->index();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('digibee_monitor_sessions');
    }
};
