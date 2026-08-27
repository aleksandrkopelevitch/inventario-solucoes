<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * The page's new owner. Nullable here on purpose — this migration only opens
 * the column; the row-by-row backfill is the next one, and the column only
 * becomes NOT NULL (with the container columns dropped) in the one after that.
 * Three steps rather than one so a half-run deploy leaves readable data.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('documentation_pages', function (Blueprint $table) {
            $table->foreignId('notebook_id')
                ->nullable()
                ->after('id')
                ->constrained()
                ->cascadeOnDelete();
        });
    }

    public function down(): void
    {
        Schema::table('documentation_pages', function (Blueprint $table) {
            $table->dropConstrainedForeignId('notebook_id');
        });
    }
};
