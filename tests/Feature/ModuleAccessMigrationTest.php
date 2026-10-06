<?php

use App\Enums\AccessModule;
use App\Models\User;
use Illuminate\Foundation\Testing\LazilyRefreshDatabase;
use Illuminate\Support\Facades\DB;

uses(LazilyRefreshDatabase::class);

/**
 * Nobody may lose — or gain — what they could do the day the per-module levels
 * landed. The migration is run backwards to get the old shape, fed one account
 * per old tier, and run forwards again.
 */
it('carries every old tier over to the module levels that match it', function () {
    User::query()->exists(); // boots the lazily refreshed schema
    $migration = require database_path('migrations/2026_10_06_144544_add_module_access_to_users_table.php');
    $migration->down();

    foreach (['admin', 'writer', 'viewer', 'reader'] as $role) {
        DB::table('users')->insert([
            'name'       => $role, 'email' => "{$role}@leomadeiras.com.br", 'password' => 'x', 'role' => $role,
            'created_at' => now(), 'updated_at' => now(),
        ]);
    }

    $migration->up();

    $user = fn (string $role) => User::where('email', "{$role}@leomadeiras.com.br")->sole();

    foreach (AccessModule::cases() as $module) {
        expect($user('admin')->canEdit($module))->toBeTrue()
            ->and($user('writer')->canEdit($module))->toBeTrue()
            // Including the Especialista and the Comitê, which are None for a
            // NEW account — the defaults must not reach back to existing ones.
            ->and($user('viewer')->canView($module))->toBeTrue()
            ->and($user('viewer')->canEdit($module))->toBeFalse()
            // The knowledge-base-only tier: closed to every module, `/docs` still open.
            ->and($user('reader')->canView($module))->toBeFalse();
    }

    expect($user('reader')->isAdmin())->toBeFalse();
});
