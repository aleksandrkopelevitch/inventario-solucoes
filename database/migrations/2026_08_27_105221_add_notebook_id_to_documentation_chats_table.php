<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * The Assiste IA conversation is scoped to whatever owns the context documents,
 * and that is the notebook now.
 *
 * The backfill reads the chat's TARGET PAGE, never the old `solution_id`: the
 * page is where the truth lives (the column was only ever a cached pointer at
 * the page's container, re-synced on every request precisely because it could
 * go stale). A chat whose target page has since been deleted has nothing to
 * point at and is deleted with it — an orphan thread about a page nobody can
 * open is not worth carrying across.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('documentation_chats', function (Blueprint $table) {
            $table->foreignId('notebook_id')->nullable()->after('user_id')->constrained()->cascadeOnDelete();
        });

        DB::table('documentation_chats')->update([
            'notebook_id' => DB::table('documentation_pages')
                ->select('notebook_id')
                ->whereColumn('documentation_pages.id', 'documentation_chats.target_id')
                ->limit(1),
        ]);

        DB::table('documentation_chats')->whereNull('notebook_id')->delete();

        Schema::table('documentation_chats', function (Blueprint $table) {
            $table->foreignId('notebook_id')->nullable(false)->change();
            $table->dropConstrainedForeignId('solution_id');
        });
    }

    public function down(): void
    {
        Schema::table('documentation_chats', function (Blueprint $table) {
            $table->foreignId('solution_id')->nullable()->constrained()->cascadeOnDelete();
            $table->dropConstrainedForeignId('notebook_id');
        });
    }
};
