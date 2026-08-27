<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Closes the container swap: `notebook_id` becomes mandatory and the
 * polymorphic pair goes away.
 *
 * The unique index moves with it. A page's slug was only ever unique WITHIN its
 * container (two solutions could each hold a "visao-geral"), and that stays
 * true of a notebook — it is the same guarantee, expressed against one column
 * instead of two, and it is what `DocumentationPageService::uniqueSlugFrom()`
 * relies on when a page changes notebook.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('documentation_pages', function (Blueprint $table) {
            $table->dropUnique(['container_type', 'container_id', 'slug']);
            $table->dropIndex(['container_type', 'container_id']);
            $table->dropColumn(['container_type', 'container_id']);
        });

        Schema::table('documentation_pages', function (Blueprint $table) {
            $table->foreignId('notebook_id')->nullable(false)->change();
            $table->unique(['notebook_id', 'slug']);
        });
    }

    public function down(): void
    {
        Schema::table('documentation_pages', function (Blueprint $table) {
            $table->dropUnique(['notebook_id', 'slug']);
            $table->foreignId('notebook_id')->nullable()->change();
            $table->string('container_type')->nullable();
            $table->unsignedBigInteger('container_id')->nullable();
        });
    }
};
