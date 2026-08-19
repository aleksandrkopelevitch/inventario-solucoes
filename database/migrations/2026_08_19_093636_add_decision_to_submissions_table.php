<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * What the committee decided, on the record rather than in a meeting minute.
 *
 * `conditions` is a json list of `{text, done}` because "aprovada com ressalvas"
 * is only worth anything if the ressalvas are trackable afterwards — a
 * condition buried in a paragraph is a condition nobody follows up on.
 *
 * `promoted_at` marks that an approved submission's sections were pushed into
 * the Solution's documentation, so the catalog carries what the committee
 * approved instead of it living only in a deck.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('submissions', function (Blueprint $table) {
            $table->text('decision')->nullable()->after('status');
            $table->json('conditions')->nullable()->after('decision');
            $table->timestamp('decided_at')->nullable()->after('conditions');
            $table->foreignId('decided_by_id')->nullable()->after('decided_at')->constrained('users')->nullOnDelete();
            $table->timestamp('promoted_at')->nullable()->after('decided_by_id');
        });
    }

    public function down(): void
    {
        Schema::table('submissions', function (Blueprint $table) {
            $table->dropConstrainedForeignId('decided_by_id');
            $table->dropColumn(['decision', 'conditions', 'decided_at', 'promoted_at']);
        });
    }
};
