<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Indexes for the foreign keys and orderings the app queries by, which
 * Postgres never created on its own: `foreignId()->constrained()` adds the
 * constraint and NO index there (MySQL adds one implicitly), the same gap
 * 2026_07_17_180455_add_indexes_to_flowspec_foreign_keys closed for the
 * flowSpec tables.
 *
 * Each one names the read it serves:
 *
 * - documentation_pages (notebook_id, position): every `$notebook->pages()`
 *   is `orderBy('position')`; the unique (notebook_id, slug) only covers the
 *   filter half.
 * - documentation_pages.parent_id: `children()`, nesting, the cascade delete.
 * - documentation_pages (notebook_id, updated_at): the catalog's "Última
 *   atualização" (`Notebook::lastUpdatedPage()`, a latestOfMany).
 * - diagrams.notebook_id: a caderno's drawings and their counts.
 * - the three pivots' solution_id: each unique index LEADS with the other
 *   side, so "every caderno / diagram / person of this solution" scanned.
 * - people.company_id, solutions.vendor_company_id: the companies catalog's
 *   counts and the vendor eager loads.
 * - pipeline_runs.flowspec_message_id: looked up once per message in the
 *   flowSpec thread.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('documentation_pages', function (Blueprint $table) {
            $table->index(['notebook_id', 'position']);
            $table->index('parent_id');
            $table->index(['notebook_id', 'updated_at']);
        });

        Schema::table('diagrams', function (Blueprint $table) {
            $table->index('notebook_id');
        });

        Schema::table('diagram_solution', function (Blueprint $table) {
            $table->index('solution_id');
        });

        Schema::table('notebook_solution', function (Blueprint $table) {
            $table->index('solution_id');
        });

        Schema::table('person_solution', function (Blueprint $table) {
            $table->index('solution_id');
        });

        Schema::table('people', function (Blueprint $table) {
            $table->index('company_id');
        });

        Schema::table('solutions', function (Blueprint $table) {
            $table->index('vendor_company_id');
        });

        Schema::table('pipeline_runs', function (Blueprint $table) {
            $table->index('flowspec_message_id');
        });
    }

    public function down(): void
    {
        Schema::table('pipeline_runs', function (Blueprint $table) {
            $table->dropIndex(['flowspec_message_id']);
        });

        Schema::table('solutions', function (Blueprint $table) {
            $table->dropIndex(['vendor_company_id']);
        });

        Schema::table('people', function (Blueprint $table) {
            $table->dropIndex(['company_id']);
        });

        Schema::table('person_solution', function (Blueprint $table) {
            $table->dropIndex(['solution_id']);
        });

        Schema::table('notebook_solution', function (Blueprint $table) {
            $table->dropIndex(['solution_id']);
        });

        Schema::table('diagram_solution', function (Blueprint $table) {
            $table->dropIndex(['solution_id']);
        });

        Schema::table('diagrams', function (Blueprint $table) {
            $table->dropIndex(['notebook_id']);
        });

        Schema::table('documentation_pages', function (Blueprint $table) {
            $table->dropIndex(['notebook_id', 'updated_at']);
            $table->dropIndex(['parent_id']);
            $table->dropIndex(['notebook_id', 'position']);
        });
    }
};
