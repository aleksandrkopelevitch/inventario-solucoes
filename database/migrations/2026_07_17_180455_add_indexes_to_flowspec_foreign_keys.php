<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Indexes the flowSpec foreign-key columns. The create migrations declared
 * them with `foreignId()->constrained()`, which on PostgreSQL (production)
 * creates the FK constraint but NOT an index on the referencing column —
 * unlike MySQL. `flowspec_messages.flowspec_chat_id` is on the hottest path
 * (every thread render and status poll loads a chat's messages), and the
 * `flowspec_chats` FKs back the "my chats" listing and integration linkage.
 * Added as a separate migration so the already-shipped create migrations are
 * left untouched.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('flowspec_messages', function (Blueprint $table) {
            $table->index('flowspec_chat_id');
        });

        Schema::table('flowspec_chats', function (Blueprint $table) {
            $table->index('user_id');
            $table->index('integration_id');
        });
    }

    public function down(): void
    {
        Schema::table('flowspec_messages', function (Blueprint $table) {
            $table->dropIndex(['flowspec_chat_id']);
        });

        Schema::table('flowspec_chats', function (Blueprint $table) {
            $table->dropIndex(['user_id']);
            $table->dropIndex(['integration_id']);
        });
    }
};
