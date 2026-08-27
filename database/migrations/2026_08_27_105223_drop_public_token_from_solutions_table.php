<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * The magic link belongs to the notebook it shares. Every token was copied onto
 * the solution's own notebook before this runs, so the links already handed out
 * keep resolving — through `notebooks.public_token` instead of this column.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('solutions', function (Blueprint $table) {
            // Explicitly, and BEFORE the column: SQLite refuses to drop a
            // column an index still names ("1 error in index
            // solutions_public_token_unique after drop column"), where Postgres
            // drops the index along with it. The test suite runs on SQLite, the
            // dev and production databases on Postgres — so a migration that
            // only ever ran against Postgres would look correct right up until
            // CI.
            $table->dropUnique('solutions_public_token_unique');
            $table->dropColumn('public_token');
        });
    }

    public function down(): void
    {
        Schema::table('solutions', function (Blueprint $table) {
            $table->string('public_token', 64)->nullable()->unique()->after('slug');
        });
    }
};
