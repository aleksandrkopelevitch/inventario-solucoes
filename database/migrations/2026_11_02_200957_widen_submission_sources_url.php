<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * `submission_sources.url` was a varchar(255) while StoreSubmissionSourceRequest
 * allows 2048 characters — and an ordinary SharePoint or Teams link is longer
 * than 255. Postgres refuses the insert (SQLSTATE 22001) and the person got a
 * 500; the suite never saw it, since sqlite does not enforce the length.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('submission_sources', function (Blueprint $table) {
            $table->string('url', 2048)->nullable()->change();
        });
    }

    /**
     * Back to 255 — which fails if a longer link was stored meanwhile; that is
     * the honest answer, rather than truncating somebody's link.
     */
    public function down(): void
    {
        Schema::table('submission_sources', function (Blueprint $table) {
            $table->string('url')->nullable()->change();
        });
    }
};
