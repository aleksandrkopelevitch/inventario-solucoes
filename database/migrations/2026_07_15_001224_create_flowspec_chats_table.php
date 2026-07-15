<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Conversas do gerador de flowSpec (F8): cada chat pertence a um usuário e
 * pode, opcionalmente, ficar vinculado à Integration que receberá o flowSpec
 * gerado (`integrations.generated_flowspec`).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('flowspec_chats', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->foreignId('integration_id')->nullable()->constrained()->nullOnDelete();
            $table->string('title');
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('flowspec_chats');
    }
};
