<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * The standalone group is a Notebook now — every row was copied across by
 * `migrate_documentation_containers_to_notebooks`, and nothing references this
 * table any more.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::dropIfExists('documentation_groups');
    }

    public function down(): void
    {
        Schema::create('documentation_groups', function (Blueprint $table) {
            $table->id();
            $table->string('name');
            $table->string('slug')->unique();
            $table->timestamps();
        });
    }
};
