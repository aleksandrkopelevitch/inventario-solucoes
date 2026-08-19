<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * The adversarial pre-review's findings, kept on the submission.
 *
 * Stored rather than shown once and forgotten: a finding is only useful if it
 * is still on screen while the section it objects to is being rewritten.
 * `pre_reviewed_at` doubles as the "still running" marker the page polls on.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('submissions', function (Blueprint $table) {
            $table->json('pre_review')->nullable()->after('promoted_at');
            $table->timestamp('pre_review_requested_at')->nullable()->after('pre_review');
            $table->timestamp('pre_reviewed_at')->nullable()->after('pre_review_requested_at');
        });
    }

    public function down(): void
    {
        Schema::table('submissions', function (Blueprint $table) {
            $table->dropColumn(['pre_review', 'pre_review_requested_at', 'pre_reviewed_at']);
        });
    }
};
