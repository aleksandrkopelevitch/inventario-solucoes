<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('integrations', function (Blueprint $table) {
            // Ver comentário gêmeo em add_documentation_to_solutions_table:
            // Markdown + notação GitBook, editor de blocos, GitbookRenderer.
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
