<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Who created a documentation page and who saved it last.
 *
 * Both nullable, and both stay null for every page that already exists: nothing
 * recorded authorship before this, and the imported GitBook corpus was written
 * by a console command, not by a person. Guessing an author for those would be
 * worse than saying "não registrado".
 *
 * `nullOnDelete` so removing a user never takes documentation with it; the
 * model reads both relations `withTrashed()`, since users are soft-deleted and
 * a departed author's name is still the honest answer.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('documentation_pages', function (Blueprint $table) {
            $table->foreignId('created_by_id')->nullable()->constrained('users')->nullOnDelete();
            $table->foreignId('updated_by_id')->nullable()->constrained('users')->nullOnDelete();
        });
    }

    public function down(): void
    {
        Schema::table('documentation_pages', function (Blueprint $table) {
            $table->dropConstrainedForeignId('created_by_id');
            $table->dropConstrainedForeignId('updated_by_id');
        });
    }
};
