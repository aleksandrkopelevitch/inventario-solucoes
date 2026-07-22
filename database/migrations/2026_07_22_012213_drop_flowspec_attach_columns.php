<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Removes the "attach a generated flowSpec onto an Integration" feature. An
 * integration can carry many different flowSpecs, so pinning a single one to
 * the record never made sense — the documentation is written by hand as a
 * dedicated page in the Documentation module instead. Drops the derived
 * columns on `integrations` and the chat -> integration link on
 * `flowspec_chats`.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('integrations', function (Blueprint $table) {
            $table->dropColumn(['generated_flowspec', 'flowspec_status', 'flowspec_generated_at']);
        });

        Schema::table('flowspec_chats', function (Blueprint $table) {
            $table->dropForeign(['integration_id']);
            $table->dropIndex(['integration_id']);
            $table->dropColumn('integration_id');
        });
    }

    public function down(): void
    {
        Schema::table('integrations', function (Blueprint $table) {
            $table->jsonb('generated_flowspec')->nullable()->after('criticality');
            $table->string('flowspec_status')->default('idle')->after('generated_flowspec');
            $table->timestamp('flowspec_generated_at')->nullable()->after('flowspec_status');
        });

        Schema::table('flowspec_chats', function (Blueprint $table) {
            $table->foreignId('integration_id')->nullable()->after('user_id')->constrained()->nullOnDelete();
            $table->index('integration_id');
        });
    }
};
