<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Messages in a flowspec_chat. `flow_spec` carries the generated JSON when
 * the assistant's message contains a pipeline; `meta` audits the generation
 * (examples used, tokens, validation attempts).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('flowspec_messages', function (Blueprint $table) {
            $table->id();
            $table->foreignId('flowspec_chat_id')->constrained()->cascadeOnDelete();
            $table->string('role');
            $table->text('content');
            $table->jsonb('flow_spec')->nullable();
            $table->jsonb('meta')->nullable();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('flowspec_messages');
    }
};
