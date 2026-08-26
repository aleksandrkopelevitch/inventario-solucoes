<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * `Integration` became `Diagram`: the chain canvas is now a first-class,
 * standalone artifact instead of something an integration owned, and the
 * integration entity is gone with it (its documentation surface with it — see
 * `move_diagram_documentation_into_pages`).
 *
 * A rename, not a new table: the 21 rows here carry the 12 chains actually
 * drawn so far plus every derived column the ecosystem map reads. Nothing
 * about the shape changed, only what the thing is called.
 *
 * `approved_topologies.integration_id` follows the rename, being the only other
 * FK that pointed at this table.
 *
 * The `diagram` jsonb column goes for good in the process. It was the
 * topology's source of truth until `chain` replaced it on 2026-07-06 and has
 * been dead since (no cast, no `$fillable` entry, no reader anywhere), and a
 * column named `diagram` on a table named `diagrams` would read as the thing
 * itself rather than as the leftover it is.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('integrations', function (Blueprint $table) {
            $table->dropColumn('diagram');
        });

        Schema::rename('integrations', 'diagrams');
        Schema::rename('integration_solution', 'diagram_solution');

        Schema::table('diagram_solution', function (Blueprint $table) {
            $table->renameColumn('integration_id', 'diagram_id');
        });

        // The one other FK pointing here: a committee's approved TO BE, once
        // someone applies it onto a real drawing.
        Schema::table('approved_topologies', function (Blueprint $table) {
            $table->renameColumn('integration_id', 'diagram_id');
        });
    }

    public function down(): void
    {
        Schema::table('approved_topologies', function (Blueprint $table) {
            $table->renameColumn('diagram_id', 'integration_id');
        });

        Schema::table('diagram_solution', function (Blueprint $table) {
            $table->renameColumn('diagram_id', 'integration_id');
        });

        Schema::rename('diagram_solution', 'integration_solution');
        Schema::rename('diagrams', 'integrations');

        Schema::table('integrations', function (Blueprint $table) {
            $table->jsonb('diagram')->nullable()->after('criticality');
        });
    }
};
