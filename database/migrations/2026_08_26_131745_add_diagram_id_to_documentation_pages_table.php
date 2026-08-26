<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * A page's optional link to a `Diagram`, which is how a diagram reaches a
 * solution now: the FK lives HERE, on the page, so ONE diagram serves 1..N
 * pages (the same drawing legitimately explains several pages, and often
 * several solutions' pages) while a page never has to reconcile two drawings.
 *
 * `nullOnDelete`, not cascade: deleting a diagram must never take documentation
 * with it — the text is the thing that took work to write.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('documentation_pages', function (Blueprint $table) {
            $table->foreignId('diagram_id')->nullable()->after('documentation')
                ->constrained()->nullOnDelete();
        });
    }

    public function down(): void
    {
        Schema::table('documentation_pages', function (Blueprint $table) {
            $table->dropConstrainedForeignId('diagram_id');
        });
    }
};
