<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Drops the message->corpus-example link: promoting a chat message into the
 * corpus was removed in favor of managing the reference base directly (the
 * admin "Referências" modal — FlowspecExampleController), so the column and
 * its FK are dead.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('flowspec_messages', function (Blueprint $table) {
            $table->dropConstrainedForeignId('flowspec_example_id');
        });
    }

    public function down(): void
    {
        Schema::table('flowspec_messages', function (Blueprint $table) {
            $table->foreignId('flowspec_example_id')
                ->nullable()
                ->constrained()
                ->nullOnDelete();
        });
    }
};
