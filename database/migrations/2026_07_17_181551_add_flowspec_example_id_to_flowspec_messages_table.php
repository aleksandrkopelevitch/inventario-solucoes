<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Links an assistant message to the corpus example it was promoted into, so
 * promotion is idempotent: a message can be promoted once, the UI reflects it,
 * and a re-promote is refused. Nulls out if the example is later deleted (the
 * message can then be promoted again).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('flowspec_messages', function (Blueprint $table) {
            $table->foreignId('flowspec_example_id')
                ->nullable()
                ->constrained()
                ->nullOnDelete();
        });
    }

    public function down(): void
    {
        Schema::table('flowspec_messages', function (Blueprint $table) {
            $table->dropConstrainedForeignId('flowspec_example_id');
        });
    }
};
