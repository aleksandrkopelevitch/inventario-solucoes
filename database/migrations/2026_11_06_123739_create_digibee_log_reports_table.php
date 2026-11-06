<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Reports collected from the Digibee logs on the iSol server — by the "Logs
 * Digibee" screen (a queued job) or by the `/mcp` search tool. The executions
 * themselves live in a redacted JSON file under private storage; the row holds
 * the question, who asked it and how it went. Pruned past the platform's own
 * ten-day log retention.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('digibee_log_reports', function (Blueprint $table) {
            $table->id();
            // What a URL and a chat name it by: never the numeric id.
            $table->uuid('uuid')->unique();
            $table->foreignId('user_id')->nullable()->constrained()->nullOnDelete();
            // A program holding a minted MCP token, when no person asked.
            $table->foreignId('mcp_token_id')->nullable()->constrained()->nullOnDelete();
            $table->string('source', 16);
            $table->string('status', 16)->index();
            $table->string('environment', 8);
            $table->string('pipeline')->nullable();
            $table->text('term')->nullable();
            $table->string('where', 16);
            $table->timestamp('from_at');
            $table->timestamp('to_at');
            $table->unsignedInteger('executions_found')->nullable();
            $table->unsignedInteger('executions_opened')->nullable();
            $table->text('error')->nullable();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('digibee_log_reports');
    }
};
