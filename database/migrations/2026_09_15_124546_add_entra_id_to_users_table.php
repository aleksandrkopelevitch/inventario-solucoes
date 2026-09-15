<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * The Entra object id (`oid`) of the account that signs in through SSO.
 *
 * Nullable and unique: most accounts in this app were invited by an admin and
 * have never seen Entra, and the seeded `admin@leomadeiras.com.br` never will.
 *
 * Stored ALONGSIDE the e-mail rather than instead of it, because the two answer
 * different questions. The e-mail is how a person who was invited BEFORE their
 * first SSO sign-in is recognised (that match happens exactly once, and is what
 * keeps an editor from being provisioned as a fresh reader); the `oid` is what
 * identifies them FROM then on, and it is the half that survives a surname
 * change or a rename of the mailbox.
 *
 * `password` stays NOT NULL — an SSO account gets an unusable random one, the
 * same shape `GrantPersonAccess::invite()` already writes.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->string('entra_id')->nullable()->unique()->after('email');
        });
    }

    public function down(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->dropColumn('entra_id');
        });
    }
};
