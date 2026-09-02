<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Which OTHER documentation pages a Documentation Assistant turn was given as
 * context, beside the caderno's uploaded context documents
 * (`context_media_ids`).
 *
 * A list of ids rather than a pivot table, and for the same reason
 * `context_media_ids` is: it is a record of what one TURN was shown, not a
 * relation between two records. A page later deleted or re-filed leaves the id
 * behind, which is correct — it is what the model was actually given.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('documentation_chat_messages', function (Blueprint $table) {
            $table->json('context_page_ids')->nullable()->after('context_media_ids');
        });
    }

    public function down(): void
    {
        Schema::table('documentation_chat_messages', function (Blueprint $table) {
            $table->dropColumn('context_page_ids');
        });
    }
};
