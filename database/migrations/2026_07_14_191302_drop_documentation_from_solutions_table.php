<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Conteúdo já migrado pra `documentation_pages` (ver migration anterior)
     * — a Solution passa a ter 1..N páginas em vez de um blob único.
     */
    public function up(): void
    {
        Schema::table('solutions', function (Blueprint $table) {
            $table->dropColumn('documentation');
        });
    }

    public function down(): void
    {
        Schema::table('solutions', function (Blueprint $table) {
            $table->longText('documentation')->nullable()->after('support_operation_note');
        });
    }
};
