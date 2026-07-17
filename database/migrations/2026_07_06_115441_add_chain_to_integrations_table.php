<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Linear integration chain defined via form (modal in the solution's
     * detail page): `{"nodes": [{"solution_id": int|null, "label": string|null}],
     * "arrows": ["->"|"<-", ...]}`. It's the source of truth for participants/
     * source/target/direction — the `diagram` (canvas) becomes purely visual.
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
