<?php

use App\Models\Solution;
use App\Models\User;
use Illuminate\Foundation\Testing\LazilyRefreshDatabase;

uses(LazilyRefreshDatabase::class);

/**
 * `bootstrap/app.php`'s NotFoundHttpException renderer only reaches
 * `resources/views/errors/404.blade.php` when `app.debug` is off (it hands
 * over to Laravel's debug page otherwise), so these tests turn it off
 * explicitly.
 */
beforeEach(function () {
    config()->set('app.debug', false);
});

it('serves a branded, self-contained 404 to an unauthenticated visitor with a dead public-docs link', function () {
    // The one unauthenticated surface in the app: a partner opening an
    // expired/revoked/mistyped magic link used to get Laravel's generic
    // unbranded 404 with no idea how to get a working one.
    $content = $this->get(route('public.docs.notebook', ['token' => 'nao-existe']))
        ->assertNotFound()
        ->getContent();

    expect($content)
        ->toContain('Página não encontrada')
        ->toContain('peça um novo link a quem o enviou')
        // Must not try to render the authenticated app shell (sidebar +
        // signed-in user's avatar) for a visitor with no account.
        ->not->toContain('id="side-panel"')
        // No dead end back to a login screen they can't use.
        ->not->toContain('Voltar ao início');
});

it('offers a way back to the app on an internal 404 for a signed-in user', function () {
    $content = $this->actingAs(User::factory()->create())
        ->get('/solutions/' . Solution::factory()->create()->slug . '/rota-que-nao-existe')
        ->assertNotFound()
        ->getContent();

    expect($content)
        ->toContain('Página não encontrada')
        ->toContain('Voltar ao início');
});
