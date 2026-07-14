<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Layout visual da visualização gráfica F3 (posições dos blocos + âncoras das
 * pontas das setas), salvo por integração. É PURAMENTE visual — não deriva
 * topologia (a `chain` continua a fonte de verdade). Coluna própria em vez de
 * reaproveitar a `diagram` órfã (do editor de canvas removido) para não
 * confundir semânticas.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('integrations', function (Blueprint $table) {
            $table->json('viz_layout')->nullable()->after('chain');
        });
    }

    public function down(): void
    {
        Schema::table('integrations', function (Blueprint $table) {
            $table->dropColumn('viz_layout');
        });
    }
};
