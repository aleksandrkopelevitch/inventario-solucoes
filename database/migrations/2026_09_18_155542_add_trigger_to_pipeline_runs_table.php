<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * What kind of trigger the run should write into the pipeline.
 *
 * The lifecycle never asked, so every pipeline it created was born with an
 * empty `triggerSpec` and the deploy was refused by the platform. The two
 * values beside the kind are the ones a flowSpec cannot yield — a scheduler's
 * cron and an event's name — and they are nullable because the three
 * web-protocol kinds need neither.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('pipeline_runs', function (Blueprint $table) {
            $table->string('trigger_kind')->nullable()->after('creates');
            $table->string('trigger_cron')->nullable()->after('trigger_kind');
            $table->string('trigger_event')->nullable()->after('trigger_cron');
        });
    }

    public function down(): void
    {
        Schema::table('pipeline_runs', function (Blueprint $table) {
            $table->dropColumn(['trigger_kind', 'trigger_cron', 'trigger_event']);
        });
    }
};
