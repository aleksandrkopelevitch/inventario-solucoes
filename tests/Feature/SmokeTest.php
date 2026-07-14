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
