<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Gives the documentation page tree its second level: a page can now sit under
 * another page of the SAME container, and nothing deeper than that (a page
 * with a parent can never be a parent itself — see
 * `DocumentationPage::canReceiveChildren()`).
 *
 * `position` keeps its meaning but narrows its scope: it orders a page among
 * its SIBLINGS (pages sharing the same `parent_id`), not among everything in
 * the container. Existing rows all become roots with `parent_id` null, so
 * their current order carries over untouched and no data migration is needed.
 *
 * `cascadeOnDelete` is the safety net, not the mechanism: the app deletes a
 * page through the model so Spatie can clean up the embedded media, and
 * `DocumentationPage::booted()` deletes the children the same way for the same
 * reason. A raw DB delete would leave orphaned files behind but never orphaned
 * rows.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('documentation_pages', function (Blueprint $table) {
            $table->foreignId('parent_id')
                ->nullable()
                ->after('container_id')
                ->constrained('documentation_pages')
                ->cascadeOnDelete();
        });
    }

    public function down(): void
    {
        Schema::table('documentation_pages', function (Blueprint $table) {
            $table->dropConstrainedForeignId('parent_id');
        });
    }
};
