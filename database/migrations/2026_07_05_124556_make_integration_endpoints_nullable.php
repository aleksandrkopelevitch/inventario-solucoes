<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Source/target are no longer required: an integration starts as an
     * empty canvas draft and these fields become derived from the diagram
     * on save. The FKs (cascade/nullOnDelete) remain.
     */
    public function up(): void
    {
        Schema::table('integrations', function (Blueprint $table) {
            $table->foreignId('source_solution_id')->nullable()->change();
            $table->foreignId('target_solution_id')->nullable()->change();
        });
    }

    public function down(): void
    {
        Schema::table('integrations', function (Blueprint $table) {
            $table->foreignId('source_solution_id')->nullable(false)->change();
            $table->foreignId('target_solution_id')->nullable(false)->change();
        });
    }
};
