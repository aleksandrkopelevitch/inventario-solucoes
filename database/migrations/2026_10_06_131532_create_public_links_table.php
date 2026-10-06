<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Magic links for screens that are not a caderno.
 *
 * A caderno keeps its token on its own row (`notebooks.public_token`) because
 * there is one link per caderno. The solutions spreadsheet is a single screen
 * over the whole catalog, so there is no row of its own to hang a token off —
 * `subject` names the screen instead, and is unique: one live link per screen,
 * revoked by deleting the row.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('public_links', function (Blueprint $table) {
            $table->id();
            $table->string('subject')->unique();
            $table->string('token')->unique();
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('public_links');
    }
};
