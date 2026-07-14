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
        Schema::create('documentation_pages', function (Blueprint $table) {
            $table->id();
            $table->string('container_type');
            $table->unsignedBigInteger('container_id');
            $table->string('title');
            $table->string('slug');
            $table->longText('documentation')->nullable();
            $table->unsignedInteger('position')->default(0);
            $table->timestamps();

            $table->index(['container_type', 'container_id']);
            $table->unique(['container_type', 'container_id', 'slug']);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('documentation_pages');
    }
};
