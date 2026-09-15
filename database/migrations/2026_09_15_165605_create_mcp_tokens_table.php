<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Bearer tokens for the MCP server (`POST /mcp`).
     *
     * An MCP token is a CREDENTIAL and not an account: it belongs to the app,
     * an admin mints it, and it is pasted into Claude/ChatGPT/Gemini as a
     * static header. So `token_hash` and not the token — the same rule as a
     * password, for the same reason: a database dump must not be a working set
     * of keys. `last_four` is what the admin screen shows, since a token is
     * invisible after the one screen that prints it.
     */
    public function up(): void
    {
        Schema::create('mcp_tokens', function (Blueprint $table) {
            $table->id();
            $table->string('name');
            // sha256 hex — a lookup key, so it is indexed and unique rather
            // than bcrypt-compared row by row. Safe because the plaintext is a
            // 40-char CSPRNG string and not a human-chosen password: there is
            // no dictionary for a rainbow table to be built from.
            $table->string('token_hash', 64)->unique();
            $table->string('last_four', 4);
            // Who minted it. Nullable and `nullOnDelete` — revoking the admin's
            // own account must not take every token they created with it.
            $table->foreignId('created_by_user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('last_used_at')->nullable();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('mcp_tokens');
    }
};
