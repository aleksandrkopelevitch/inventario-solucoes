<?php

use App\Models\Notebook;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * The caderno's secret code — the short string that unlocks the protected
 * values inside its pages (`{% secret %}` … `{% endsecret %}`, see
 * App\Support\Documentation\SecretText).
 *
 * Nullable in the schema and backfilled here, so every caderno that already
 * exists has one: a page can grow a protected value at any moment, and a
 * notebook whose code was null would answer every reveal attempt with "wrong
 * code" — indistinguishable, from the reader's side, from a bad guess.
 *
 * Stored in the clear on purpose. A hash would close nothing that matters (the
 * plaintext of the protected value sits in `documentation_pages.documentation`
 * in the same database) and would break the one requirement the feature has:
 * an admin has to be able to READ the code off the share panel and pass it on.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('notebooks', function (Blueprint $table) {
            $table->string('secret_code', 32)->nullable()->after('public_token');
        });

        Notebook::query()->whereNull('secret_code')->cursor()->each(
            fn (Notebook $notebook) => $notebook->rotateSecretCode(),
        );
    }

    public function down(): void
    {
        Schema::table('notebooks', function (Blueprint $table) {
            $table->dropColumn('secret_code');
        });
    }
};
