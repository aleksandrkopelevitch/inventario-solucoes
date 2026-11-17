<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * One row per change an MCP write tool made — who, through which connector,
 * what it touched, and the fields as they were and as written — so an admin
 * can read what a chat did and revert it (App\Models\McpWrite).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('mcp_writes', function (Blueprint $table) {
            $table->id();
            // nullOnDelete: an account's removal must not erase the record of
            // what it changed — that record is what makes the change revertible.
            $table->foreignId('user_id')->nullable()->constrained()->nullOnDelete();
            // Which connector (Claude.ai, ChatGPT…) — a Passport client is a
            // uuid, and it is kept as a plain value rather than a foreign key:
            // a connection deleted later must not take its history with it.
            $table->uuid('oauth_client_id')->nullable()->index();
            $table->string('tool', 64);
            $table->morphs('subject');
            // Encrypted JSON (see the model): a page body holds its protected
            // values in clear, and so would its before/after.
            $table->longText('before')->nullable();
            $table->longText('after');
            $table->timestamp('reverted_at')->nullable();
            $table->foreignId('reverted_by_id')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('created_at')->index();

            $table->index(['user_id', 'created_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('mcp_writes');
    }
};
