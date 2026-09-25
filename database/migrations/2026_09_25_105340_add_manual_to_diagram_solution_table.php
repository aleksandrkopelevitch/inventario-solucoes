<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Says who wrote a participant row.
     *
     * Until now every row was derived from the chain by `SyncDiagramFromChain`,
     * which detaches the whole set and rebuilds it on each mutation. That is
     * still true of the rows this column leaves at `false`, and it is what
     * keeps the ecosystem map a reading of the DRAWINGS. What it could not
     * express is a drawing whose systems are not blocks: a generated process
     * or data-flow diagram is lanes and neutral steps, so it named no solution
     * at all and reached neither the map nor any solution's page.
     *
     * A row at `true` is somebody's own statement that the drawing concerns
     * that system. The derivation never writes it and never removes it — the
     * only thing that does is the person editing the diagram.
     */
    public function up(): void
    {
        Schema::table('diagram_solution', function (Blueprint $table) {
            $table->boolean('manual')->default(false)->after('solution_id');
        });
    }

    public function down(): void
    {
        Schema::table('diagram_solution', function (Blueprint $table) {
            $table->dropColumn('manual');
        });
    }
};
