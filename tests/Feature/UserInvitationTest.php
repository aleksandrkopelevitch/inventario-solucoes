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
