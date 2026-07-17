<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * flowSpec generator (F8) conversations: each chat belongs to a user and can
 * optionally be linked to the Integration that will receive the generated
 * flowSpec (`integrations.generated_flowspec`).
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
