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
        Schema::create('flow_solution', function (Blueprint $table) {
            $table->foreignId('flow_id')->constrained()->cascadeOnDelete();
            $table->foreignId('solution_id')->constrained()->cascadeOnDelete();
            $table->timestamps();

            $table->unique(['flow_id', 'solution_id']);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('flow_solution');
    }
};
