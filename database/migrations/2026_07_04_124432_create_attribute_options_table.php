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
        Schema::create('attribute_options', function (Blueprint $table) {
            $table->id();
            $table->string('group'); // see App\Enums\AttributeGroup
            $table->string('value'); // stored in solutions/integrations columns
            $table->string('label'); // text shown to the user
            $table->timestamps();

            $table->unique(['group', 'value']);
            $table->index('group');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('attribute_options');
    }
};
