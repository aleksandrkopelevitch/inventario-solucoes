<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Who leads the Comitê de Arquitetura. Recording a submission's deliberation
 * is an admin's or a leader's (SubmissionPolicy::decide) — not its author's,
 * who until now could record the committee's verdict on their own proposal.
 * Set by an admin on the account's access card; more than one account may
 * hold it.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->boolean('committee_leader')->default(false)->after('access');
        });
    }

    public function down(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->dropColumn('committee_leader');
        });
    }
};
