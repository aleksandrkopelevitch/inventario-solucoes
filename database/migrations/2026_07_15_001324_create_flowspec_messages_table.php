<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Mensagens de um flowspec_chat. `flow_spec` carrega o JSON gerado quando a
 * mensagem do assistant contém um pipeline; `meta` audita a geração (exemplos
 * usados, tokens, tentativas de validação).
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
