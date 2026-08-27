<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Which Solutions a Notebook documents — many-to-many, and the whole point of
 * the revamp: a body of documentation describes several systems far more often
 * than it describes exactly one, and the old `container` could only ever name
 * one owner.
 *
 * Both sides cascade: the pivot row is a statement about two records, and it
 * means nothing once either is gone. Nothing else hangs off it (no position,
 * no "primary" flag) — a solution's documentation is simply the union of its
 * notebooks.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('notebook_solution', function (Blueprint $table) {
            $table->id();
            $table->foreignId('notebook_id')->constrained()->cascadeOnDelete();
            $table->foreignId('solution_id')->constrained()->cascadeOnDelete();
            $table->timestamps();

            $table->unique(['notebook_id', 'solution_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('notebook_solution');
    }
};
