<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * From one app-wide tier to a level per module.
 *
 * `role` keeps only member/admin; what an ordinary account may do moves to
 * `access` — `{"catalog": "editor", "committee": "none", …}`, a missing module
 * meaning that module's default (`AccessModule::defaultLevel()`).
 *
 * Existing accounts get ALL FOUR modules written down rather than left to the
 * defaults, which are about NEW accounts and do not match what the old tiers
 * could do (a `viewer` read the Especialista and the Comitê; the default for a
 * new account there is None). So nobody gains or loses anything:
 *
 * - `writer` edited everything → Editor in all four;
 * - `viewer` read everything → Reader in all four;
 * - `reader` (the tier Entra SSO provisioned) reached the published knowledge
 *   base and nothing else → None in all four (`/docs` stays open to it).
 */
return new class extends Migration
{
    private const MODULES = ['catalog', 'documentation', 'integrations', 'committee'];

    public function up(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->json('access')->nullable()->after('role');
        });

        foreach (['writer' => 'editor', 'viewer' => 'reader', 'reader' => 'none'] as $role => $level) {
            DB::table('users')->where('role', $role)
                ->update(['role' => 'member', 'access' => json_encode(array_fill_keys(self::MODULES, $level))]);
        }

        Schema::table('users', function (Blueprint $table) {
            $table->string('role')->default('member')->change();
        });
    }

    public function down(): void
    {
        // An editor of ANY module was writing content, so it maps back to the
        // old app-wide `writer`; a member closed to every module was a `reader`.
        DB::table('users')->where('role', 'member')->whereNotNull('access')
            ->whereRaw('CAST(access AS TEXT) LIKE ?', ['%editor%'])->update(['role' => 'writer']);
        DB::table('users')->where('role', 'member')->whereNotNull('access')
            ->whereRaw('CAST(access AS TEXT) LIKE ?', ['%none%'])->update(['role' => 'reader']);
        DB::table('users')->where('role', 'member')->update(['role' => 'viewer']);

        Schema::table('users', function (Blueprint $table) {
            $table->string('role')->default('viewer')->change();
            $table->dropColumn('access');
        });
    }
};
