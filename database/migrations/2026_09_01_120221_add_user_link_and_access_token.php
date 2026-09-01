<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Links a `Person` to the account they log in with, and gives that account the
 * token behind its access link.
 *
 * `people` and `users` had no relation at all: a Person was a catalog record
 * (name, company, job title, who owns which system) and a User was an account,
 * and nothing said the two were the same human. Access was therefore managed on
 * a screen of its own, about nobody in particular.
 *
 * **Both directions stay optional, and that is the point.** Most people in the
 * catalog are vendor contacts who will never log in — 106 of the 108 rows in dev
 * do not even have an email — so `user_id` is nullable. And an account without a
 * Person is normal too: `admin@leomadeiras.com.br` is the seeded bootstrap admin
 * and always will be, which is why the accounts list has to keep existing rather
 * than being replaced by "the people who happen to have one".
 *
 * `nullOnDelete`: removing an account must never take the person out of the
 * catalog. `unique` so one account is never claimed by two people.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('people', function (Blueprint $table) {
            $table->foreignId('user_id')->nullable()->after('slug')
                ->constrained()->nullOnDelete();
            $table->unique('user_id');
        });

        Schema::table('users', function (Blueprint $table) {
            // The access link's token. It lives on the ACCOUNT rather than on
            // the person because it is the account it opens — and because
            // revoking it has to be possible for an account whose Person link
            // was never made.
            //
            // Long-lived (7 days) and reusable on purpose: it is handed over by
            // hand, over Teams or in person, and a single-use link that someone
            // opens on the wrong device is a support request. What it opens is
            // narrow enough to make that safe — see App\Actions\GrantPersonAccess
            // and the `access.*` routes: it leads to the password screen and
            // never to a session.
            $table->string('access_token', 64)->nullable()->unique()->after('remember_token');
            $table->timestamp('access_token_expires_at')->nullable()->after('access_token');
        });
    }

    public function down(): void
    {
        Schema::table('people', function (Blueprint $table) {
            $table->dropUnique(['user_id']);
            $table->dropConstrainedForeignId('user_id');
        });

        Schema::table('users', function (Blueprint $table) {
            $table->dropColumn(['access_token', 'access_token_expires_at']);
        });
    }
};
