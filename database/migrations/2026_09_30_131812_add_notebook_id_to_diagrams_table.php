<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * A diagram belongs to exactly one caderno. Required, because a drawing that
 * belongs nowhere is precisely what this replaces; cascades, because a caderno
 * deleted takes its drawings the way it takes its pages.
 *
 * NOT NULL is safe only because `purge_test_diagrams` ran first and left the
 * table empty. It is added nullable and tightened in a second statement, the
 * way `finalize_notebook_id_on_documentation_pages_table` did it: SQLite (the
 * test database) refuses to ADD a NOT NULL column that has no default.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('diagrams', function (Blueprint $table) {
            $table->foreignId('notebook_id')->nullable()->after('id')->constrained()->cascadeOnDelete();
        });

        Schema::table('diagrams', function (Blueprint $table) {
            $table->foreignId('notebook_id')->nullable(false)->change();
        });
    }

    public function down(): void
    {
        Schema::table('diagrams', function (Blueprint $table) {
            $table->dropConstrainedForeignId('notebook_id');
        });
    }
};
