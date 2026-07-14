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
            $table->string('group'); // ver App\Enums\AttributeGroup
            $table->string('value'); // gravado nas colunas de solutions/integrations
            $table->string('label'); // texto exibido ao usuário
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
