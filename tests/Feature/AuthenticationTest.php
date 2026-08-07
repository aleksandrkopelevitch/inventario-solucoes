<?php

use App\Models\User;
use Illuminate\Foundation\Testing\LazilyRefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Password;

uses(LazilyRefreshDatabase::class);

it('logs in with correct credentials and regenerates the session', function () {
    $user = User::factory()->create(['password' => Hash::make('correct-password')]);

    $response = $this->postJson(route('login.store'), [
        'email'    => $user->email,
        'password' => 'correct-password',
    ])->assertOk();

    expect($response->json('redirect'))->toBe(route('profile.show'));
    $this->assertAuthenticatedAs($user);
});

it('rejects a login with the wrong password without revealing which field is wrong', function () {
    $user = User::factory()->create(['password' => Hash::make('correct-password')]);

    $this->postJson(route('login.store'), [
        'email'    => $user->email,
        'password' => 'wrong-password',
    ])->assertStatus(422)->assertJson(['type' => 'warning']);

    $this->assertGuest();
});

it('throttles repeated login attempts', function () {
    // Time is frozen because this test asserts a behaviour defined by a WINDOW
    // ("6 attempts per minute", `throttle:6,1` on the route), and it must not
    // depend on where the wall clock happens to be while it runs.
    //
    // It used to fail ~35% of the time here. The rate limiter keeps two array
    // cache entries with a 60s TTL (the counter and its `:timer`); when the
    // clock jumps past that TTL both are dropped, the counter restarts, and the
    // 7th request is no longer throttled — a 200 instead of a 429. Verified by
    // logging `time()` per request: one request reported a timestamp 66 seconds
    // ahead of the ones before AND after it, with `Carbon::hasTestNow()` false,
    // i.e. the host clock stepping (this dev box is WSL2 with `System clock
    // synchronized: no`), not anything in the app. Freezing also makes the test
    // honest on a slow CI box, where six bcrypt hashes could legitimately
    // straddle a real minute boundary.
    $this->freezeTime();

    $user = User::factory()->create(['password' => Hash::make('correct-password')]);

    for ($i = 0; $i < 6; $i++) {
        $this->postJson(route('login.store'), [
            'email'    => $user->email,
            'password' => 'wrong-password',
        ])->assertStatus(422);
    }

    // The 7th attempt in the same window is throttled, even with the right password.
    $this->postJson(route('login.store'), [
        'email'    => $user->email,
        'password' => 'correct-password',
    ])->assertStatus(429);

    $this->assertGuest();
});

it('logs out and invalidates the session', function () {
    $user = User::factory()->create();

    $this->actingAs($user)
        ->delete(route('login.destroy'))
        ->assertRedirect(route('login.create'));

    $this->assertGuest();
});

it('has no public self-registration route', function () {
    $this->get('/register')->assertNotFound();
    $this->postJson('/register', [])->assertNotFound();
});

it('sends a reset link for a known email and lets the user set a new password with it', function () {
    $user = User::factory()->create(['password' => Hash::make('senha-antiga')]);

    $this->postJson(route('password.email'), ['email' => $user->email])
        ->assertOk()->assertJson(['type' => 'success']);

    $token = Password::createToken($user);

    $this->postJson(route('password.update'), [
        'token'                 => $token,
        'email'                 => $user->email,
        'password'              => 'senha-nova-123',
        'password_confirmation' => 'senha-nova-123',
    ])->assertOk()->assertJson(['type' => 'success']);

    expect(Hash::check('senha-nova-123', $user->fresh()->password))->toBeTrue();

    // The old password no longer works.
    $this->postJson(route('login.store'), [
        'email'    => $user->email,
        'password' => 'senha-antiga',
    ])->assertStatus(422);
});

it('rejects a reset attempt with a bad token, without touching the password', function () {
    $user = User::factory()->create(['password' => Hash::make('senha-antiga')]);

    $this->postJson(route('password.update'), [
        'token'                 => 'token-invalido',
        'email'                 => $user->email,
        'password'              => 'senha-nova-123',
        'password_confirmation' => 'senha-nova-123',
    ])->assertStatus(422);

    expect(Hash::check('senha-antiga', $user->fresh()->password))->toBeTrue();
});

it('gives the same generic response for a forgot-password request whether or not the email exists', function () {
    $user = User::factory()->create();

    $known = $this->postJson(route('password.email'), ['email' => $user->email])->assertOk();
    $unknown = $this->postJson(route('password.email'), ['email' => 'ninguem@leomadeiras.com.br'])->assertOk();

    expect($known->json())->toBe($unknown->json());
});
