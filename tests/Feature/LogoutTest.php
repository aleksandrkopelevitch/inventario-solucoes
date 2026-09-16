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
 * one who already had an account here under another address. They arrive as a
 * `Reader`, land in `/docs`, and every inventory route redirects them back to
 * it (App\Http\Middleware\EnsureInventoryAccess), so without a way out the
 * knowledge base is the whole application, permanently.
 */
it('offers a way out from inside the knowledge base, which is a reader’s whole app', function () {
    Notebook::factory()->published()->create();

    $this->actingAs(User::factory()->reader()->create())
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

/** A reader only reaches the inventory the moment an admin promotes them. */
it('sends a reader back to the knowledge base until their role changes', function () {
    $user = User::factory()->reader()->create();

    $this->actingAs($user)->get(route('profile.show'))->assertRedirect(route('docs.index'));

    $user->update(['role' => UserRole::Admin]);

    $this->actingAs($user->fresh())->get(route('profile.show'))->assertOk();
});

/**
 * Every tier, and the `Reader` is the one this test exists for.
 *
 * `login.destroy` sat inside the `inventory` route group, so
 * `EnsureInventoryAccess` answered it before the controller ever did: a reader
 * submitting the form was redirected to `/docs` and stayed signed in. The
 * button did nothing, silently, for the only tier whose entire application is
 * the screen it was added to. Signing out is not an inventory action — it is
 * `auth` and nothing else.
 */
it('ends the session and returns to the login screen, whatever the account may read', function (UserRole $role) {
    $this->actingAs(User::factory()->create(['role' => $role]))
        ->delete(route('login.destroy'))
        ->assertRedirect(route('login.create'));

    $this->assertGuest();
})->with([
    'reader' => UserRole::Reader,
    'viewer' => UserRole::Viewer,
    'writer' => UserRole::Writer,
    'admin'  => UserRole::Admin,
]);

/** The gate that used to swallow it still guards everything it should. */
it('keeps the inventory itself closed to a reader', function () {
    $this->actingAs(User::factory()->reader()->create())
        ->get(route('solutions.index'))
        ->assertRedirect(route('docs.index'));
});
