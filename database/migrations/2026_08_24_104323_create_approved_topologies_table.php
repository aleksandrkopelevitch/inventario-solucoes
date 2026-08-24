<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * A TO BE the committee approved, and whether the catalog has caught up with
 * it yet.
 *
 * `chain` / `viz_layout` are a SNAPSHOT, not a reference to the submission's
 * diagram: what gets applied has to be what was approved. The submitter can
 * keep editing that drawing afterwards — a pending change that quietly became
 * a different drawing is worse than no record at all.
 *
 * `applied_at` and `dismissed_at` are both nullable and mutually exclusive in
 * practice: null/null is pending, and the two outcomes are deliberately
 * different — "the catalog now says this" and "the catalog was already right"
 * are not the same claim, and collapsing them would lose the only distinction
 * anyone auditing this cares about.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('approved_topologies', function (Blueprint $table) {
            $table->id();

            // One per submission: a submission is deliberated once, and a
            // second row would make "which topology was approved" a question.
            $table->foreignId('submission_id')->unique()->constrained()->cascadeOnDelete();
            $table->foreignId('solution_id')->constrained()->cascadeOnDelete();

            $table->json('chain');
            $table->json('viz_layout')->nullable();

            $table->timestamp('approved_at');

            // Where it landed. Null while pending; nulled again if that
            // integration is later deleted, which leaves an applied record
            // pointing nowhere rather than deleting the history of it.
            $table->foreignId('integration_id')->nullable()->constrained()->nullOnDelete();
            $table->timestamp('applied_at')->nullable();
            $table->foreignId('applied_by_id')->nullable()->constrained('users')->nullOnDelete();

            $table->timestamp('dismissed_at')->nullable();
            $table->foreignId('dismissed_by_id')->nullable()->constrained('users')->nullOnDelete();
            $table->string('dismissed_reason')->nullable();

            $table->timestamps();

            // The one query the Solution page runs: "does this solution owe the
            // catalog a topology update?"
            $table->index(['solution_id', 'applied_at', 'dismissed_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('approved_topologies');
    }
};
