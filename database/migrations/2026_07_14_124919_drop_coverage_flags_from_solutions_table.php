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
        Schema::table('solutions', function (Blueprint $table) {
            $table->dropColumn([
                'has_macro_architecture',
                'has_detailed_components',
                'has_business_flows',
            ]);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('solutions', function (Blueprint $table) {
            $table->boolean('has_macro_architecture')->default(false);
            $table->boolean('has_detailed_components')->default(false);
            $table->boolean('has_business_flows')->default(false);
        });
    }
};
