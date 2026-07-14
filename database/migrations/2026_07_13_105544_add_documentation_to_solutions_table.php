<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('solutions', function (Blueprint $table) {
            // Documentação rica no formato Markdown + notação estendida estilo
            // GitBook ({% hint %}, {% tabs %}, <figure>/<img src="/files/{id}">),
            // autorada no editor de blocos (Editor.js) e renderizada por
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
