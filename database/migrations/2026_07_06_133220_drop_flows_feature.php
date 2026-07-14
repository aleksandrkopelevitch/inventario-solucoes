<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Remove o F6 (diagramas de arquitetura livres, modo autoria do canvas)
     * por completo — decisão de 2026-07-06: o editor de diagrama de
     * integrações (`integrations.diagram`) desenha os fluxos no novo padrão
     * e tornou o F6 redundante. `flow_solution` antes de `flows` por causa
     * da FK.
     */
    public function up(): void
    {
        Schema::dropIfExists('flow_solution');
        Schema::dropIfExists('flows');
    }

    public function down(): void
    {
        Schema::create('flows', function (Blueprint $table) {
            $table->id();
            $table->string('name');
            $table->string('slug')->unique();
            $table->text('description')->nullable();
            $table->json('flow_json')->default('{}');
            $table->timestamps();
        });

        Schema::create('flow_solution', function (Blueprint $table) {
            $table->foreignId('flow_id')->constrained()->cascadeOnDelete();
            $table->foreignId('solution_id')->constrained()->cascadeOnDelete();
            $table->timestamps();

            $table->unique(['flow_id', 'solution_id']);
        });
    }
};
