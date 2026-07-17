<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('solutions', function (Blueprint $table) {
            // Rich documentation in Markdown format + extended GitBook-style
            // notation ({% hint %}, {% tabs %}, <figure>/<img src="/files/{id}">),
            // authored in the block editor (Editor.js) and rendered by
            // App\Support\GitbookRenderer.
            $table->longText('documentation')->nullable()->after('support_operation_note');
        });
    }

    public function down(): void
    {
        Schema::table('solutions', function (Blueprint $table) {
            $table->dropColumn('documentation');
        });
    }
};
