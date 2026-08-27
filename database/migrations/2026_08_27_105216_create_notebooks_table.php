<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * A Notebook ("Caderno") — the one and only container of documentation pages,
 * modelled on a GitBook Space. It replaces both halves of the old polymorphic
 * `container`: the standalone `DocumentationGroup` (which was already being
 * used exactly this way) and the Solution that used to own its pages directly.
 *
 * `public_token` moves here from `solutions` for the same reason: what gets
 * shared through a magic link is a body of documentation, and a body of
 * documentation is now a notebook. The tokens already handed out are carried
 * over verbatim by the data migration, so every link already in the wild keeps
 * resolving.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('notebooks', function (Blueprint $table) {
            $table->id();
            $table->string('name');
            $table->string('slug')->unique();
            // Public documentation link token ("magic link"). Null = not
            // shared; clearing the token revokes the old link.
            $table->string('public_token', 64)->nullable()->unique();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('notebooks');
    }
};
