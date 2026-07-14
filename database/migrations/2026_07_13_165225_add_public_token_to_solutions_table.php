<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::table('solutions', function (Blueprint $table) {
            // Token do link público de documentação ("magic link"). Nulo = doc
            // não compartilhada; regenerar o token revoga o link antigo.
            $table->string('public_token', 64)->nullable()->unique()->after('slug');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('solutions', function (Blueprint $table) {
            $table->dropColumn('public_token');
        });
    }
};
