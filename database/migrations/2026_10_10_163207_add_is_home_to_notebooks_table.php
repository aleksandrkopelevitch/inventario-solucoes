<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Marks the caderno whose first page IS the knowledge base's landing (`/docs`).
 *
 * A flag on a caderno rather than a separate "home" entity, so the landing is
 * written in the same editor, with the same blocks, images and history as any
 * other documentation — "editable in Cadernos" is the whole requirement.
 *
 * A boolean and not a timestamp like `published_at`: nobody asks "since when is
 * this the home". One caderno at most is meant to carry it; the code reads the
 * first published one (`Notebook::scopeHome()`), which is also why a home that
 * gets unpublished simply stops being shown rather than breaking `/docs`.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('notebooks', function (Blueprint $table) {
            $table->boolean('is_home')->default(false)->index();
        });
    }

    public function down(): void
    {
        Schema::table('notebooks', function (Blueprint $table) {
            $table->dropColumn('is_home');
        });
    }
};
