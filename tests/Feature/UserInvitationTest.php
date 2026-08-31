<?php

use App\Enums\UserRole;
use App\Mail\UserInvitationMail;
use App\Models\User;
use Illuminate\Foundation\Testing\LazilyRefreshDatabase;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Password;

uses(LazilyRefreshDatabase::class);

it('lets an admin open the users management modal', function () {
    $admin = User::factory()->create(['role' => UserRole::Admin->value]);

    $this->actingAs($admin)
        ->getJson(route('users.index'))
        ->assertOk();
});

it('forbids a non-admin from viewing or inviting users', function () {
    $viewer = User::factory()->create();

    $this->actingAs($viewer)->getJson(route('users.index'))->assertForbidden();
    $this->actingAs($viewer)
        ->postJson(route('users.store'), ['name' => 'X', 'email' => 'x@leomadeiras.com.br', 'role' => 'viewer'])
        ->assertForbidden();
});

it('invites a new user and queues the invitation email', function () {
    Mail::fake();
    $admin = User::factory()->create(['role' => UserRole::Admin->value]);

    $response = $this->actingAs($admin)
        ->postJson(route('users.store'), [
            'name'  => 'Nova Pessoa',
            'email' => 'nova@leomadeiras.com.br',
            'role'  => 'viewer',
        ])->assertOk()->assertJson(['type' => 'success']);

    expect($response->json('updatableSlots.0.id'))->toBe('users-list-slot');

    $invited = User::firstWhere('email', 'nova@leomadeiras.com.br');
    expect($invited)->not->toBeNull()
        ->and($invited->role)->toBe(UserRole::Viewer);

    Mail::assertQueued(UserInvitationMail::class, fn ($mail) => $mail->user->is($invited));
});

it('rejects an invite with a duplicate email', function () {
    $admin = User::factory()->create(['role' => UserRole::Admin->value]);
    $existing = User::factory()->create();

    $this->actingAs($admin)
        ->postJson(route('users.store'), [
            'name'  => 'Duplicado',
            'email' => $existing->email,
            'role'  => 'viewer',
        ])->assertStatus(422);
});

it('rejects an invite with a role outside viewer/admin', function () {
    $admin = User::factory()->create(['role' => UserRole::Admin->value]);

    $this->actingAs($admin)
        ->postJson(route('users.store'), [
            'name'  => 'Alguém',
            'email' => 'alguem@leomadeiras.com.br',
            'role'  => 'agent',
        ])->assertStatus(422);
});

it('lets an invited user set their password via the reset-password flow and log in', function () {
    $admin = User::factory()->create(['role' => UserRole::Admin->value]);

    $this->actingAs($admin)->postJson(route('users.store'), [
        'name'  => 'Convidada',
        'email' => 'convidada@leomadeiras.com.br',
        'role'  => 'viewer',
    ])->assertOk();

    $invited = User::firstWhere('email', 'convidada@leomadeiras.com.br');

    // Log the admin back out — the `guest` middleware on login/reset would
    // otherwise redirect every request below before it ever reaches the
    // controller, since the client is still authenticated as the admin.
    $this->delete(route('login.destroy'));

    // The invited user can't log in yet — their password is an unusable random string.
    $this->postJson(route('login.store'), [
        'email'    => $invited->email,
        'password' => 'qualquer-coisa',
    ])->assertStatus(422);

    $token = Password::createToken($invited);
    $this->postJson(route('password.update'), [
        'token'                 => $token,
        'email'                 => $invited->email,
        'password'              => 'minha-senha-123',
        'password_confirmation' => 'minha-senha-123',
    ])->assertOk();

    $this->postJson(route('login.store'), [
        'email'    => $invited->email,
        'password' => 'minha-senha-123',
    ])->assertOk();
    $this->assertAuthenticatedAs($invited);
});

/*
|--------------------------------------------------------------------------
| Changing a role
|--------------------------------------------------------------------------
|
| The one administrative act that used to have no screen: a role could only be
| picked at invite time, so promoting a viewer to editor — or taking the admin
| off somebody who left — meant an UPDATE against the production database.
|
*/

it('lets an admin promote a viewer to editor', function () {
    $admin = User::factory()->create(['role' => UserRole::Admin->value]);
    $viewer = User::factory()->create(['role' => UserRole::Viewer->value]);

    $response = $this->actingAs($admin)
        ->patchJson(route('users.update', $viewer), ['role' => UserRole::Writer->value])
        ->assertOk()
        ->assertJson(['type' => 'success']);

    expect($viewer->fresh()->role)->toBe(UserRole::Writer)
        ->and($response->json('message'))->toContain('Editor')
        ->and($response->json('updatableSlots.0.id'))->toBe('users-list-slot');
});

it('lets an admin demote another admin while one remains', function () {
    $admin = User::factory()->create(['role' => UserRole::Admin->value]);
    $other = User::factory()->create(['role' => UserRole::Admin->value]);

    $this->actingAs($admin)
        ->patchJson(route('users.update', $other), ['role' => UserRole::Viewer->value])
        ->assertOk();

    expect($other->fresh()->role)->toBe(UserRole::Viewer);
});

it('refuses to change your own role, whoever you are', function () {
    $admin = User::factory()->create(['role' => UserRole::Admin->value]);
    User::factory()->create(['role' => UserRole::Admin->value]); // not the last admin

    $response = $this->actingAs($admin)
        ->patchJson(route('users.update', $admin), ['role' => UserRole::Viewer->value])
        ->assertStatus(422);

    expect($response->json('message'))->toContain('seu próprio perfil')
        ->and($admin->fresh()->role)->toBe(UserRole::Admin);
});

it('leaves the last admin standing, since the only account that could demote them is their own', function () {
    // The invariant, and the proof that it needs no guard of its own: taking an
    // admin's role requires an admin asking about SOMEBODY ELSE, which means two
    // admins exist. Down to one, both doors are shut — 422 for that admin, 403
    // for everyone else, since nobody else may reach this endpoint at all.
    $admin = User::factory()->create(['role' => UserRole::Admin->value]);
    $second = User::factory()->create(['role' => UserRole::Admin->value]);

    $this->actingAs($admin)
        ->patchJson(route('users.update', $second), ['role' => UserRole::Viewer->value])
        ->assertOk();

    expect(User::where('role', UserRole::Admin->value)->count())->toBe(1);

    $this->actingAs($admin)
        ->patchJson(route('users.update', $admin), ['role' => UserRole::Viewer->value])
        ->assertStatus(422);

    $this->actingAs($second->fresh())
        ->patchJson(route('users.update', $admin), ['role' => UserRole::Viewer->value])
        ->assertForbidden();

    expect($admin->fresh()->role)->toBe(UserRole::Admin)
        ->and(User::where('role', UserRole::Admin->value)->count())->toBe(1);
});

it('refuses an editor and a viewer outright', function () {
    $target = User::factory()->create(['role' => UserRole::Viewer->value]);

    foreach ([UserRole::Writer, UserRole::Viewer] as $role) {
        $this->actingAs(User::factory()->create(['role' => $role->value]))
            ->patchJson(route('users.update', $target), ['role' => UserRole::Admin->value])
            ->assertForbidden();
    }

    expect($target->fresh()->role)->toBe(UserRole::Viewer);
});

it('rejects a role the enum does not know', function () {
    $admin = User::factory()->create(['role' => UserRole::Admin->value]);
    $target = User::factory()->create(['role' => UserRole::Viewer->value]);

    $this->actingAs($admin)
        ->patchJson(route('users.update', $target), ['role' => 'superadmin'])
        ->assertStatus(422);

    expect($target->fresh()->role)->toBe(UserRole::Viewer);
});

it('offers the select on other rows and withholds it on your own', function () {
    $admin = User::factory()->create(['role' => UserRole::Admin->value, 'name' => 'Eu Mesmo']);
    User::factory()->create(['role' => UserRole::Admin->value, 'name' => 'Outro Admin']);
    User::factory()->create(['role' => UserRole::Viewer->value, 'name' => 'Alguem Viewer']);

    $content = $this->actingAs($admin)->getJson(route('users.index'))->assertOk()->json('content');

    // One editable row per account that is not mine (the second admin and the
    // viewer), and the action points at each of them. The URL is compared
    // JSON-encoded because that is how the component carries it — `json_encode`
    // escapes the slashes, so the raw string never appears in the HTML.
    $actionOf = fn (User $user) => trim(json_encode(route('users.update', $user)), '"');

    expect(substr_count($content, 'data-ak-inline-edit='))->toBe(2)
        ->and($content)->toContain('Eu Mesmo')
        ->and($content)->toContain($actionOf(User::where('name', 'Alguem Viewer')->sole()))
        ->and($content)->not->toContain($actionOf($admin));
});
