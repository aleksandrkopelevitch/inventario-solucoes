<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Who looked up what in the Digibee logs through iSol. Every read reaches
 * Digibee as the one account whose session the server holds, so the
 * platform's own audit names that person for everybody; this is the record
 * of who actually asked.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('digibee_log_lookups', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->nullable()->constrained()->nullOnDelete();
            $table->foreignId('mcp_token_id')->nullable()->constrained()->nullOnDelete();
            $table->string('source', 16);
            // count | list | report | execution | query
            $table->string('action', 16);
            $table->string('environment', 8)->nullable();
            $table->string('pipeline')->nullable();
            $table->text('term')->nullable();
            $table->string('execution_key')->nullable();
            $table->timestamp('created_at')->useCurrent()->index();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('digibee_log_lookups');
    }
};
