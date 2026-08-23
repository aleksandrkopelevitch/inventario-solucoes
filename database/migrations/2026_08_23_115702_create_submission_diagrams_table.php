<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * The four drawings a submission carries (App\Enums\SubmissionDiagramKind).
 *
 * `chain` and `viz_layout` mirror the columns on `integrations` exactly,
 * because the same canvas writes both — see App\Contracts\ChainCanvas. They
 * stay null on the two uploaded kinds, which carry a media file instead.
 *
 * Deliberately NOT columns on `submissions`: four kinds × two json columns
 * would be eight columns, six of them null on any given row, and adding a
 * fifth drawing later would be another migration on a wide table.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('submission_diagrams', function (Blueprint $table) {
            $table->id();
            $table->foreignId('submission_id')->constrained()->cascadeOnDelete();
            $table->string('kind');

            // Topology and presentation, same split and same shape as an
            // Integration's: the chain is the source of truth, the layout is
            // purely visual and must never drive it.
            $table->json('chain')->nullable();
            $table->json('viz_layout')->nullable();

            $table->timestamps();

            // One drawing per kind per submission — the UI offers exactly four
            // slots, and a second AS IS would make "which one goes on the
            // slide" a question nobody should have to answer.
            $table->unique(['submission_id', 'kind']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('submission_diagrams');
    }
};
