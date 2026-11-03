<?php

use App\Http\Requests\ConfirmEmailChangeRequest;
use App\Mail\ConfirmEmailChangeMail;
use App\Models\User;
use Illuminate\Foundation\Testing\LazilyRefreshDatabase;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Password;
use Illuminate\Support\Facades\URL;

uses(LazilyRefreshDatabase::class);

/*
|--------------------------------------------------------------------------
| Changing your own e-mail needs the new mailbox to confirm
|--------------------------------------------------------------------------
| The e-mail is how an account is FOUND (an admin's grant, SSO's first
| visit), so setting it to a colleague's address must not be a form field.
*/

function confirmUrl(User $user, string $email, ?string $from = null): string
{
    return URL::temporarySignedRoute('profile.email.confirm', now()->addDay(), [
        'user'  => $user->id,
        'email' => $email,
        'from'  => $from ?? ConfirmEmailChangeRequest::addressFingerprint($user->email),
    ]);
}

it('keeps the old address and mails a link to the new one', function () {
    Mail::fake();
    $user = User::factory()->create(['email' => 'antigo@leomadeiras.com.br']);

    $this->actingAs($user)
        ->patchJson(route('profile.update'), ['name' => 'Novo Nome', 'email' => 'novo@leomadeiras.com.br'])
        ->assertOk()
        ->assertJsonFragment(['type' => 'success']);

    expect($user->fresh()->email)->toBe('antigo@leomadeiras.com.br')
        ->and($user->fresh()->name)->toBe('Novo Nome');

    Mail::assertQueued(ConfirmEmailChangeMail::class, fn ($mail) => $mail->hasTo('novo@leomadeiras.com.br'));
});

it('sends nothing when only the case of the address changed', function () {
    Mail::fake();
    $user = User::factory()->create(['email' => 'ana@leomadeiras.com.br']);

    $this->actingAs($user)
        ->patchJson(route('profile.update'), ['name' => $user->name, 'email' => 'Ana@leomadeiras.com.br'])
        ->assertOk();

    Mail::assertNothingQueued();
});

it('changes the address once the link is opened', function () {
    $user = User::factory()->create(['email' => 'antigo@leomadeiras.com.br']);

    $this->actingAs($user)
        ->get(confirmUrl($user, 'novo@leomadeiras.com.br'))
        ->assertRedirect(route('profile.show'));

    expect($user->fresh()->email)->toBe('novo@leomadeiras.com.br');
});

it('refuses a tampered link, somebody else\'s link, and a link the account outgrew', function () {
    $user = User::factory()->create(['email' => 'antigo@leomadeiras.com.br']);
    $url = confirmUrl($user, 'novo@leomadeiras.com.br');

    // Tampered: another address in a signed URL.
    $this->actingAs($user)->get(str_replace('novo%40', 'outro%40', $url))->assertForbidden();

    // Somebody else, signed in, opening it.
    $this->actingAs(User::factory()->create())->get($url)->assertForbidden();

    // The account's e-mail changed since the link was sent.
    $user->forceFill(['email' => 'terceiro@leomadeiras.com.br'])->save();
    $this->actingAs($user->fresh())->get($url)->assertSessionHasErrors('email');

    expect($user->fresh()->email)->toBe('terceiro@leomadeiras.com.br');
});

it('refuses an address another account took meanwhile, whatever its case', function () {
    $user = User::factory()->create(['email' => 'antigo@leomadeiras.com.br']);
    $url = confirmUrl($user, 'novo@leomadeiras.com.br');
    User::factory()->create(['email' => 'Novo@leomadeiras.com.br']);

    $this->actingAs($user)->get($url)->assertSessionHasErrors('email');

    expect($user->fresh()->email)->toBe('antigo@leomadeiras.com.br');
});

/*
|--------------------------------------------------------------------------
| A password reset ends the account's other sessions
|--------------------------------------------------------------------------
*/

it('logs out a session opened with the old password once the password changes', function () {
    $user = User::factory()->create(['password' => 'senha-antiga-123']);

    $this->postJson(route('login.store'), ['email' => $user->email, 'password' => 'senha-antiga-123'])->assertOk();
    $this->get(route('profile.show'))->assertOk();

    // What the reset does, from another browser (the reset route is for
    // guests, and a feature test has one cookie jar).
    $user->forceFill(['password' => 'senha-nova-456'])->save();

    // A real next request loads the user afresh; the test's guard would
    // otherwise keep the object (and the old hash) from the last one.
    $this->app['auth']->forgetGuards();

    // The session that was already open is gone on its next request.
    $this->get(route('profile.show'))->assertRedirect(route('login.create'));
});

it('rotates the remember token when the password is reset', function () {
    $user = User::factory()->create(['remember_token' => 'lembrar-de-mim-antigo']);

    $this->postJson(route('password.update'), [
        'token'                 => Password::createToken($user),
        'email'                 => $user->email,
        'password'              => 'senha-nova-456',
        'password_confirmation' => 'senha-nova-456',
    ])->assertOk();

    expect($user->fresh()->remember_token)->not->toBe('lembrar-de-mim-antigo');
});
