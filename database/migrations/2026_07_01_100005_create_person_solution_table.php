<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('person_solution', function (Blueprint $table) {
            $table->foreignId('person_id')->constrained('people')->cascadeOnDelete();
            $table->foreignId('solution_id')->constrained('solutions')->cascadeOnDelete();
            $table->string('role'); // PersonSolutionRole enum
            $table->boolean('is_primary')->default(false);
            $table->timestamps();

            $table->unique(['person_id', 'solution_id', 'role']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('person_solution');
    }
};
