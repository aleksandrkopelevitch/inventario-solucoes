<?php

use App\Enums\UserRole;
use App\Models\Notebook;
use App\Models\User;
use Illuminate\Foundation\Testing\LazilyRefreshDatabase;
use Illuminate\Support\Str;

uses(LazilyRefreshDatabase::class);

/**
 * Signing OUT, which the route table has always offered and no screen did.
 *
 * It went unnoticed for as long as every account was created by an admin and
 * signed in with a password: nobody in that world ever needed to become
 * somebody else. Entra SSO ends it — an account is now something anybody with a
 * Leo mailbox gets by visiting once, and the person it strands is exactly the
 * one who already had an account here under another address — a brand-new
 * account, often landing in `/docs` from a shared link, with no way back to
 * the login screen unless the shell offers one.
 */
it('offers a way out from inside the knowledge base', function () {
    Notebook::factory()->published()->create();

    $this->actingAs(User::factory()->create())
        ->get(route('docs.index'))
        ->assertOk()
        ->assertSee(route('login.destroy'))
        ->assertSee('Sair');
});

it('offers the same way out from the inventory shell', function () {
    $this->actingAs(User::factory()->create(['role' => UserRole::Admin]))
        ->get(route('profile.show'))
        ->assertOk()
        ->assertSee(route('login.destroy'))
        ->assertSee('Sair');
});

/**
 * The magic link shares this layout with `/docs` and has no account behind it:
 * a token grants one caderno to somebody who never signed in. An account menu
 * there would name nobody and "Sair" would end nothing.
 */
it('shows no account menu on the magic link, where there is no session to end', function () {
    $notebook = Notebook::factory()->published()->create(['public_token' => Str::random(32)]);

    $this->get(route('public.docs.notebook', $notebook->public_token))
        ->assertOk()
        ->assertDontSee(route('login.destroy'))
        ->assertDontSee('Sair');
});

/**
 * Every role. `login.destroy` once sat inside a route group whose middleware
 * answered it before the controller did, and the button silently did nothing
 * for one tier. Signing out is not an inventory action — it is `auth` and
 * nothing else.
 */
it('ends the session and returns to the login screen, whatever the account may read', function (UserRole $role) {
    $this->actingAs(User::factory()->create(['role' => $role]))
        ->delete(route('login.destroy'))
        ->assertRedirect(route('login.create'));

    $this->assertGuest();
})->with([
    'member' => UserRole::Member,
    'admin'  => UserRole::Admin,
]);
