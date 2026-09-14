<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * One run of the Digibee lifecycle against a pipeline, started from the F8
 * conversation that produced the flowSpec.
 *
 * The rows exist so the browser can watch a queued job that takes minutes and
 * deploys several times, and `rounds` is written DURING the run rather than at
 * the end — the whole point of the screen is to show progress cycle by cycle,
 * and a report that only appears on completion would leave a person staring at
 * a spinner while real deployments happen in the realm.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('pipeline_runs', function (Blueprint $table) {
            $table->id();

            // The message carrying the `{meta, flowSpec}` this run writes. The
            // run belongs to a document, not to a conversation: a chat that
            // generated three times can have a run per generation.
            $table->foreignId('flowspec_message_id')->constrained()->cascadeOnDelete();

            // Who pressed. An editor may start one (UserPolicy-style write
            // rule), and the row is the audit of who reached the realm.
            $table->foreignId('user_id')->constrained();

            $table->string('pipeline_name');
            $table->string('environment');

            // Whether this run was allowed to CREATE the pipeline. Stored
            // rather than inferred because nothing on that platform deletes a
            // pipeline: "who made this thing exist" has to be answerable.
            $table->boolean('creates')->default(false);

            $table->string('status')->index();
            $table->string('verdict')->nullable();

            $table->json('rounds')->nullable();
            $table->json('readiness')->nullable();

            $table->string('endpoint')->nullable();
            $table->text('error')->nullable();

            $table->timestamp('started_at')->nullable();
            $table->timestamp('finished_at')->nullable();

            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('pipeline_runs');
    }
};
