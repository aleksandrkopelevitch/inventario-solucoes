<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::table('documentation_ai_generations', function (Blueprint $table) {
            // Set once the user has resolved a finished generation (applied or
            // discarded the draft, or acknowledged a failure). An unconsumed
            // finished generation is what lets the editor RESUME the "Assiste IA"
            // flow after a reload — so we don't re-surface it once handled.
            $table->timestamp('consumed_at')->nullable()->after('meta');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('documentation_ai_generations', function (Blueprint $table) {
            $table->dropColumn('consumed_at');
        });
    }
};
