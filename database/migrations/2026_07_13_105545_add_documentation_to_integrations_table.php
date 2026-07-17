<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('integrations', function (Blueprint $table) {
            // See the twin comment in add_documentation_to_solutions_table:
            // Markdown + GitBook notation, block editor, GitbookRenderer.
            $table->longText('documentation')->nullable()->after('viz_layout');
        });
    }

    public function down(): void
    {
        Schema::table('integrations', function (Blueprint $table) {
            $table->dropColumn('documentation');
        });
    }
};
