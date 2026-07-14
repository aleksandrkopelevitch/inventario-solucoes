<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Cadeia linear da integração definida via formulário (modal no detalhe
     * da solução): `{"nodes": [{"solution_id": int|null, "label": string|null}],
     * "arrows": ["->"|"<-", ...]}`. É a fonte de verdade de participants/
     * source/target/direction — o `diagram` (canvas) passa a ser só visual.
     */
    public function up(): void
    {
        Schema::table('integrations', function (Blueprint $table) {
            $table->json('chain')->nullable()->after('diagram');
        });
    }

    public function down(): void
    {
        Schema::table('integrations', function (Blueprint $table) {
            $table->dropColumn('chain');
        });
    }
};
