<?php

use App\Models\User;
use Illuminate\Foundation\Testing\LazilyRefreshDatabase;

uses(LazilyRefreshDatabase::class);

it('redirects guests from the root to the login page', function () {
    $this->get('/')->assertRedirect(route('login.create'));
});

it('renders the login screen', function () {
    $this->get(route('login.create'))->assertOk();
});

it('renders the authenticated shell (sidebar + Leo identity)', function () {
    $user = User::factory()->create();

    $this->actingAs($user)
        ->get(route('profile.show'))
        ->assertOk()
        ->assertSee('Leo Madeiras')
        ->assertSee('Visão geral');
});

it('surfaces a flash message through the app Toast', function () {
    // Until this existed, `back()->with('error', …)` — used by the throttle
    // (429) and expired-session (419) HTML paths in bootstrap/app.php — was
    // written and silently dropped: nothing in any view read session('error').
    $content = $this->actingAs(User::factory()->create())
        ->withSession(['error' => 'Sua sessão expirou.'])
        ->get(route('profile.show'))
        ->assertOk()
        ->getContent();

    // `@json()` is what makes the message safe to drop into a <script>, and it
    // escapes non-ASCII (`ã` -> `ã`) — mirroring it through json_encode
    // here keeps the assertion honest about what actually reaches the browser.
    expect($content)
        ->toContain('Toast.show(' . json_encode('Sua sessão expirou.') . ", 'warning')")
        // Must be deferred: @vite loads app.js as a module, so `Toast` does
        // not exist while the body is still being parsed.
        ->toContain("addEventListener('DOMContentLoaded'");
});

it('renders no flash script when the session carries none', function () {
    $content = $this->actingAs(User::factory()->create())
        ->get(route('profile.show'))
        ->assertOk()
        ->getContent();

    expect($content)->not->toContain('Toast.show(');
});
