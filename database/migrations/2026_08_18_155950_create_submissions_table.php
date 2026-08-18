<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * A submission to the IT Architecture Committee (CATI). The record is the
 * single authored artifact: the Leo Resolve ticket text, the Markdown
 * document and (later) the deck are all rendered FROM it, never typed
 * alongside it.
 *
 * `solution_id` is nullable on purpose — a submission can propose something
 * that isn't in the catalog yet. When it IS set, the inventory answers a good
 * part of the questionnaire on its own (App\Support\Cati\SubmissionRequirements),
 * which is the whole point of hosting this here rather than in a document tool.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('submissions', function (Blueprint $table) {
            $table->id();
            $table->string('name');
            $table->string('slug')->unique();
            $table->foreignId('solution_id')->nullable()->constrained()->nullOnDelete();
            // The person the committee will ask questions to — a catalog
            // Person (like a solution's owners), not necessarily the app user
            // who typed it, hence separate from `created_by_id`.
            $table->foreignId('requester_person_id')->nullable()->constrained('people')->nullOnDelete();
            $table->foreignId('created_by_id')->constrained('users');
            $table->string('status')->default('draft')->index();
            // Leo Resolve ticket id, filled in once the ticket is opened.
            $table->string('ticket_reference')->nullable();
            $table->date('committee_date')->nullable();
            $table->timestamps();

            // The list's default view: what is on the agenda, by date.
            $table->index(['status', 'committee_date']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('submissions');
    }
};
