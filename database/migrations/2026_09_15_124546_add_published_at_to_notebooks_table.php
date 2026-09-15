<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Publication into the internal knowledge base (`/docs`).
 *
 * A TIMESTAMP rather than a boolean, for the same reason `public_token` is a
 * token rather than an `is_shared` flag: the admin screen answers "what is
 * published, and since when", and a boolean can only answer the first half.
 * `whereNotNull` is the scope.
 *
 * Deliberately NOT the same column as `public_token`. The two are different
 * audiences and different acts: the magic link is for somebody OUTSIDE Leo and
 * carries no identity at all, `/docs` is for anybody signed in with a Leo
 * account. A caderno is legitimately published to one, the other, both or
 * neither.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('notebooks', function (Blueprint $table) {
            $table->timestamp('published_at')->nullable()->index();
        });
    }

    public function down(): void
    {
        Schema::table('notebooks', function (Blueprint $table) {
            $table->dropColumn('published_at');
        });
    }
};
