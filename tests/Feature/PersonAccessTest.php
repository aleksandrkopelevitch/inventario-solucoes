<?php

use App\Enums\UserRole;
use App\Models\Person;
use App\Models\User;
use Illuminate\Foundation\Testing\LazilyRefreshDatabase;
use Illuminate\Support\Facades\Password;

uses(LazilyRefreshDatabase::class);

/*
|--------------------------------------------------------------------------
| A person's ACCESS
|--------------------------------------------------------------------------
|
| `people` and `users` were unrelated tables, so the app could list who was able
| to log in and could not say who any of them were. Access is an attribute of a
| Person now — granted on their own page, beside the systems they own.
|
| Two invariants everything here circles: an editor may curate a person and may
| never hand out an account, and an access link leads to the password screen and
| never to a session.
|
*/

function accessAdmin(): User
{
    return User::factory()->create(['role' => UserRole::Admin->value]);
}

function personWithEmail(string $email = 'fulano@leomadeiras.com.br'): Person
{
    return Person::factory()->create(['name' => 'Fulano de Tal', 'email' => $email]);
}

/*
|--------------------------------------------------------------------------
| Granting
|--------------------------------------------------------------------------
*/

it('grants a person an account, linked and with a live access link', function () {
    $person = personWithEmail();

    $this->actingAs(accessAdmin())
        ->postJson(route('people.access.store', $person), ['role' => UserRole::Writer->value])
        ->assertOk()
        ->assertJson(['type' => 'success']);

    $account = $person->fresh()->user;

    expect($account)->not->toBeNull()
        ->and($account->email)->toBe('fulano@leomadeiras.com.br')
        ->and($account->role)->toBe(UserRole::Writer)
        ->and($account->hasLiveAccessToken())->toBeTrue()
        ->and($account->access_token_expires_at->diffInDays(now()))
        ->toBeLessThanOrEqual(User::ACCESS_TOKEN_DAYS);
});

it('refuses to grant access to a person with no email, naming the missing field', function () {
    $person = Person::factory()->create(['email' => null]);

    $response = $this->actingAs(accessAdmin())
        ->postJson(route('people.access.store', $person), ['role' => UserRole::Viewer->value])
        ->assertStatus(422);

    expect($response->json('message'))->toContain('e-mail')
        ->and($person->fresh()->user_id)->toBeNull()
        ->and(User::where('email', null)->exists())->toBeFalse();
});

it('refuses to grant twice', function () {
    $person = personWithEmail();
    $admin = accessAdmin();

    $this->actingAs($admin)->postJson(route('people.access.store', $person), ['role' => 'viewer'])->assertOk();
    $this->actingAs($admin)->postJson(route('people.access.store', $person), ['role' => 'admin'])->assertStatus(422);

    expect($person->fresh()->user->role)->toBe(UserRole::Viewer);
});

it('refuses to grant when a live account already belongs to another person', function () {
    $taken = personWithEmail('compartilhado@leomadeiras.com.br');
    $this->actingAs(accessAdmin())
        ->postJson(route('people.access.store', $taken), ['role' => 'viewer'])
        ->assertOk();

    $other = Person::factory()->create(['email' => 'compartilhado@leomadeiras.com.br']);

    $response = $this->actingAs(accessAdmin())
        ->postJson(route('people.access.store', $other), ['role' => 'viewer'])
        ->assertStatus(422);

    expect($response->json('message'))->toContain('outra pessoa');
});

/*
|--------------------------------------------------------------------------
| Who may do it
|--------------------------------------------------------------------------
*/

it('refuses an EDITOR every access action — curating a person is not handing out an account', function () {
    $person = personWithEmail();
    $editor = User::factory()->create(['role' => UserRole::Writer->value]);

    // The editor CAN edit this person…
    $this->actingAs($editor)
        ->patchJson(route('people.field.update', $person), ['job_title' => 'Analista'])
        ->assertOk();

    // …and cannot give them an account.
    $this->actingAs($editor)
        ->postJson(route('people.access.store', $person), ['role' => 'viewer'])
        ->assertForbidden();

    expect($person->fresh()->user_id)->toBeNull();
});

it('shows an editor whether a person has access, without the levers', function () {
    $person = personWithEmail();
    $this->actingAs(accessAdmin())->postJson(route('people.access.store', $person), ['role' => 'viewer'])->assertOk();

    $content = $this->actingAs(User::factory()->create(['role' => UserRole::Writer->value]))
        ->get(route('people.show', $person))
        ->assertOk()
        ->getContent();

    // The fact, yes — the access link and the revoke button, no.
    expect($content)->toContain('Acesso')
        ->and($content)->toContain($person->email)
        ->and($content)->not->toContain('Remover acesso')
        ->and($content)->not->toContain('Gerar novo link');
});

/*
|--------------------------------------------------------------------------
| The access link
|--------------------------------------------------------------------------
*/

it('sends the link holder to the password screen and NEVER logs them in', function () {
    $person = personWithEmail();
    $this->actingAs(accessAdmin())->postJson(route('people.access.store', $person), ['role' => 'viewer'])->assertOk();

    $token = $person->fresh()->user->access_token;

    // Signed out, as the holder of the link would be.
    auth()->logout();

    $response = $this->get(route('access.show', $token))->assertRedirect();

    expect($response->headers->get('Location'))->toContain('reset-password')
        // The whole design: a URL forwarded in a Teams thread must not BE the
        // account.
        ->and(auth()->check())->toBeFalse();
});

it('turns a dead link into one message that says nothing about the account', function () {
    // Never existed, already used and expired all answer the same way — telling
    // them apart tells a stranger whether the account behind a dead URL is real.
    $this->get(route('access.show', 'nao-existe-este-token'))
        ->assertRedirect(route('login.create'));

    $person = personWithEmail();
    $this->actingAs(accessAdmin())->postJson(route('people.access.store', $person), ['role' => 'viewer'])->assertOk();
    $account = $person->fresh()->user;
    $token = $account->access_token;

    $account->forceFill(['access_token_expires_at' => now()->subMinute()])->save();
    auth()->logout();

    $this->get(route('access.show', $token))->assertRedirect(route('login.create'));
});

it('spends the link the moment the password is set', function () {
    $person = personWithEmail();
    $this->actingAs(accessAdmin())->postJson(route('people.access.store', $person), ['role' => 'viewer'])->assertOk();
    $account = $person->fresh()->user;

    expect($account->hasLiveAccessToken())->toBeTrue();

    auth()->logout();

    // Exactly what the person does at the end of the link.
    $this->postJson(route('password.update'), [
        'token'                 => Password::createToken($account),
        'email'                 => $account->email,
        'password'              => 'uma-senha-bem-longa',
        'password_confirmation' => 'uma-senha-bem-longa',
    ])->assertOk();

    // Otherwise the link would be a seven-day password-reset URL for a live
    // account — account takeover for anyone it was ever forwarded to.
    expect($account->fresh()->access_token)->toBeNull()
        ->and($account->fresh()->hasLiveAccessToken())->toBeFalse();
});

it('replaces the link when a new one is generated, killing the old', function () {
    $person = personWithEmail();
    $admin = accessAdmin();
    $this->actingAs($admin)->postJson(route('people.access.store', $person), ['role' => 'viewer'])->assertOk();

    $account = $person->fresh()->user;
    $old = $account->access_token;

    $this->actingAs($admin)
        ->postJson(route('people.access.link.refresh', [$person, $account]))
        ->assertOk();

    expect($account->fresh()->access_token)->not->toBe($old);

    auth()->logout();
    $this->get(route('access.show', $old))->assertRedirect(route('login.create'));
});

it('revokes just the link, leaving the account able to log in', function () {
    $person = personWithEmail();
    $admin = accessAdmin();
    $this->actingAs($admin)->postJson(route('people.access.store', $person), ['role' => 'viewer'])->assertOk();
    $account = $person->fresh()->user;

    $this->actingAs($admin)
        ->deleteJson(route('people.access.link.destroy', [$person, $account]))
        ->assertOk();

    expect($account->fresh()->access_token)->toBeNull()
        ->and($person->fresh()->user_id)->toBe($account->id)
        ->and(User::find($account->id))->not->toBeNull();
});

/*
|--------------------------------------------------------------------------
| Revoking, and granting again
|--------------------------------------------------------------------------
*/

it('revokes access without removing the person from the catalog', function () {
    $person = personWithEmail();
    $admin = accessAdmin();
    $this->actingAs($admin)->postJson(route('people.access.store', $person), ['role' => 'writer'])->assertOk();
    $account = $person->fresh()->user;

    $this->actingAs($admin)->deleteJson(route('people.access.destroy', $person))->assertOk();

    expect($person->fresh())->not->toBeNull()
        ->and($person->fresh()->user_id)->toBeNull()
        // Soft-deleted: the auth provider's default scope refuses to load it, so
        // an existing session stops resolving — while the submissions and chats
        // it owns keep pointing at a row that exists.
        ->and(User::find($account->id))->toBeNull()
        ->and(User::withTrashed()->find($account->id))->not->toBeNull();
});

it('restores the same account when access is granted again', function () {
    $person = personWithEmail();
    $admin = accessAdmin();
    $this->actingAs($admin)->postJson(route('people.access.store', $person), ['role' => 'writer'])->assertOk();
    $firstId = $person->fresh()->user_id;

    $this->actingAs($admin)->deleteJson(route('people.access.destroy', $person))->assertOk();
    $this->actingAs($admin)->postJson(route('people.access.store', $person), ['role' => 'viewer'])->assertOk();

    // The same row, not a second account beside the first — whatever this person
    // authored stays attached to them.
    expect($person->fresh()->user_id)->toBe($firstId)
        ->and($person->fresh()->user->role)->toBe(UserRole::Viewer)
        ->and(User::withTrashed()->where('email', $person->email)->count())->toBe(1);
});

/*
|--------------------------------------------------------------------------
| Linking an account that already exists
|--------------------------------------------------------------------------
*/

it('links an orphan account to a person', function () {
    // The case that keeps the roster alive: an account with no Person.
    $orphan = User::factory()->create(['role' => UserRole::Admin->value, 'email' => 'seed@leomadeiras.com.br']);
    $person = personWithEmail('outro@leomadeiras.com.br');

    $this->actingAs(accessAdmin())
        ->patchJson(route('people.access.link', $person), ['user_id' => $orphan->id])
        ->assertOk();

    expect($person->fresh()->user_id)->toBe($orphan->id);
});

it('refuses to link an account that already belongs to somebody', function () {
    $taken = personWithEmail('um@leomadeiras.com.br');
    $this->actingAs(accessAdmin())->postJson(route('people.access.store', $taken), ['role' => 'viewer'])->assertOk();

    $other = personWithEmail('dois@leomadeiras.com.br');

    $response = $this->actingAs(accessAdmin())
        ->patchJson(route('people.access.link', $other), ['user_id' => $taken->fresh()->user_id])
        ->assertStatus(422);

    expect($response->json('message'))->toContain($taken->name)
        ->and($other->fresh()->user_id)->toBeNull();
});

/*
|--------------------------------------------------------------------------
| The roster
|--------------------------------------------------------------------------
*/

it('names the person behind each account and says when there is none', function () {
    $admin = accessAdmin();
    $person = personWithEmail();
    $this->actingAs($admin)->postJson(route('people.access.store', $person), ['role' => 'viewer'])->assertOk();

    $content = $this->actingAs($admin)->get(route('people.accounts'))->assertOk()->getContent();

    expect($content)->toContain($person->name)
        ->and($content)->toContain('link pendente')   // granted, password not set yet
        ->and($content)->toContain('sem pessoa vinculada'); // the admin doing the asking
});

it('reserves the accounts segment as a person slug', function () {
    $this->actingAs(accessAdmin())
        ->postJson(route('people.store'), ['name' => 'Accounts'])
        ->assertOk();

    // Otherwise this person would have taken the roster's URL.
    expect(Person::where('name', 'Accounts')->sole()->slug)->not->toBe('accounts');
});

it('404s when the person and the account in the URL are not linked', function () {
    // `{user}` is a global binding and `scopeBindings()` cannot scope it (a
    // person has ONE account, via belongsTo), so the pair is checked by hand.
    $mine = personWithEmail('meu@leomadeiras.com.br');
    $theirs = personWithEmail('deles@leomadeiras.com.br');
    $admin = accessAdmin();

    $this->actingAs($admin)->postJson(route('people.access.store', $mine), ['role' => 'viewer'])->assertOk();
    $this->actingAs($admin)->postJson(route('people.access.store', $theirs), ['role' => 'viewer'])->assertOk();

    $otherAccount = $theirs->fresh()->user;

    $this->actingAs($admin)
        ->patchJson(route('people.access.role', [$mine, $otherAccount]), ['role' => 'admin'])
        ->assertNotFound();

    $this->actingAs($admin)
        ->postJson(route('people.access.link.refresh', [$mine, $otherAccount]))
        ->assertNotFound();

    expect($otherAccount->fresh()->role)->toBe(UserRole::Viewer);
});

/*
|--------------------------------------------------------------------------
| Revoking from the roster — the orphans' only door
|--------------------------------------------------------------------------
*/

it('revokes an ORPHAN account from the roster, which is its only door', function () {
    // The gap this closes: revoking lived only on a person's Acesso card, so an
    // account with no Person had its role changeable there and no way to be
    // switched off anywhere at all.
    $orphan = User::factory()->create(['role' => UserRole::Viewer->value]);

    expect($orphan->person)->toBeNull();

    $this->actingAs(accessAdmin())
        ->deleteJson(route('users.destroy', $orphan))
        ->assertOk()
        ->assertJson(['type' => 'success']);

    expect(User::find($orphan->id))->toBeNull()
        ->and(User::withTrashed()->find($orphan->id))->not->toBeNull();
});

it('unlinks the person and refreshes their card when revoking from the roster', function () {
    $person = personWithEmail();
    $admin = accessAdmin();
    $this->actingAs($admin)->postJson(route('people.access.store', $person), ['role' => 'writer'])->assertOk();
    $account = $person->fresh()->user;

    $response = $this->actingAs($admin)->deleteJson(route('users.destroy', $account))->assertOk();

    // Reached by the account, so the PERSON's card is on another screen — it has
    // to be in the response or it keeps showing access that is gone.
    $slotIds = collect($response->json('updatableSlots'))->pluck('id');

    expect($slotIds)->toContain('people-accounts-slot')
        ->and($slotIds)->toContain('person-access-slot')
        ->and($person->fresh()->user_id)->toBeNull()
        ->and($person->fresh()->exists)->toBeTrue();
});

it('clears the access link when an account is revoked from the roster', function () {
    $person = personWithEmail();
    $admin = accessAdmin();
    $this->actingAs($admin)->postJson(route('people.access.store', $person), ['role' => 'viewer'])->assertOk();
    $account = $person->fresh()->user;
    $token = $account->access_token;

    $this->actingAs($admin)->deleteJson(route('users.destroy', $account))->assertOk();

    // A revoked account whose link still opened the password screen would be a
    // door left ajar.
    expect(User::withTrashed()->find($account->id)->access_token)->toBeNull();

    auth()->logout();
    $this->get(route('access.show', $token))->assertRedirect(route('login.create'));
});

it('refuses to revoke your own account, which is what keeps an admin able to log in', function () {
    $admin = accessAdmin();
    User::factory()->create(['role' => UserRole::Admin->value]);

    $response = $this->actingAs($admin)
        ->deleteJson(route('users.destroy', $admin))
        ->assertStatus(422);

    expect($response->json('message'))->toContain('seu próprio acesso')
        ->and(User::find($admin->id))->not->toBeNull();
});

it('leaves the last account with the panel able to log in', function () {
    // Same invariant as the role: revoking needs an admin asking about SOMEBODY
    // ELSE, so two panel-holders exist and one always survives. Down to one,
    // both doors are shut — 422 on their own row, 403 for anybody else.
    $admin = accessAdmin();
    $second = accessAdmin();

    $this->actingAs($admin)->deleteJson(route('users.destroy', $second))->assertOk();

    expect(User::where('role', UserRole::Admin->value)->count())->toBe(1);

    $this->actingAs($admin)->deleteJson(route('users.destroy', $admin))->assertStatus(422);
    $this->actingAs(User::factory()->create(['role' => UserRole::Writer->value]))
        ->deleteJson(route('users.destroy', $admin))
        ->assertForbidden();

    expect(User::find($admin->id))->not->toBeNull();
});

it('offers the trash on other rows of the roster and withholds it on your own', function () {
    $admin = accessAdmin();
    $other = User::factory()->create(['role' => UserRole::Viewer->value]);

    $content = $this->actingAs($admin)->get(route('people.accounts'))->assertOk()->getContent();

    expect($content)->toContain('account-revoke-' . $other->id)
        ->and($content)->not->toContain('account-revoke-' . $admin->id);
});

it('reaches the revoke endpoint the way the button actually calls it', function () {
    // Every other test here calls `deleteJson`, which proves the endpoint and
    // NOT the path the UI takes: `ajax-post.js` always POSTs and the verb is
    // spoofed by `@method('DELETE')` in the hidden form. If Laravel's method
    // override were ever off, those tests would stay green while the button 405s.
    $orphan = User::factory()->create(['role' => UserRole::Viewer->value]);

    $this->actingAs(accessAdmin())
        ->postJson(route('users.destroy', $orphan), ['_method' => 'DELETE'])
        ->assertOk();

    expect(User::find($orphan->id))->toBeNull();
});

it('reaches the person-card revoke the same way', function () {
    $person = personWithEmail();
    $admin = accessAdmin();
    $this->actingAs($admin)->postJson(route('people.access.store', $person), ['role' => 'viewer'])->assertOk();

    $this->actingAs($admin)
        ->postJson(route('people.access.destroy', $person), ['_method' => 'DELETE'])
        ->assertOk();

    expect($person->fresh()->user_id)->toBeNull();
});

/*
|--------------------------------------------------------------------------
| Unlinking — the inverse of `link()`, and NOT of `grant()`
|--------------------------------------------------------------------------
|
| The gap these close: the card offered "Vincular uma conta que já existe" and,
| as its only apparent opposite, "Remover acesso" — which soft-deletes the
| account. So correcting a mistaken link switched off the account it named, and
| linking `admin@leomadeiras.com.br` to a person and undoing it locked the
| seeded admin out of the app. Reported from the app 2026-09-01.
|
*/

it('unlinks an account from a person and leaves it able to log in', function () {
    $orphan = User::factory()->create(['role' => UserRole::Admin->value, 'email' => 'seed@leomadeiras.com.br']);
    $person = personWithEmail('outro@leomadeiras.com.br');
    $admin = accessAdmin();

    $this->actingAs($admin)->patchJson(route('people.access.link', $person), ['user_id' => $orphan->id])->assertOk();

    $this->actingAs($admin)
        ->deleteJson(route('people.access.unlink', [$person, $orphan]))
        ->assertOk()
        ->assertJson(['type' => 'success']);

    // The whole point: detached, and every one of the account's own facts intact.
    expect($person->fresh()->user_id)->toBeNull();

    $account = User::withTrashed()->find($orphan->id);

    expect($account)->not->toBeNull()
        ->and($account->trashed())->toBeFalse()
        ->and($account->role)->toBe(UserRole::Admin)
        ->and($account->person)->toBeNull();
});

it('leaves an unlinked account on the roster, linkable again', function () {
    $orphan = User::factory()->create(['role' => UserRole::Viewer->value, 'email' => 'volta@leomadeiras.com.br']);
    $person = personWithEmail('pessoa@leomadeiras.com.br');
    $admin = accessAdmin();

    $this->actingAs($admin)->patchJson(route('people.access.link', $person), ['user_id' => $orphan->id])->assertOk();
    $this->actingAs($admin)->deleteJson(route('people.access.unlink', [$person, $orphan]))->assertOk();

    // Back in the picker on the person's card, which is what makes the mistake
    // recoverable instead of merely undone.
    $content = $this->actingAs($admin)->get(route('people.show', $person->fresh()))->assertOk()->getContent();

    expect($content)->toContain('volta@leomadeiras.com.br')
        ->and($content)->toContain('Esta pessoa já tem uma conta?');
});

it('keeps the access link alive across an unlink', function () {
    // Unlinking says nothing about how somebody logs in, so a link that was
    // handed out has no reason to stop working.
    $person = personWithEmail();
    $admin = accessAdmin();
    $this->actingAs($admin)->postJson(route('people.access.store', $person), ['role' => 'viewer'])->assertOk();

    $account = $person->fresh()->user;
    $token = $account->access_token;

    $this->actingAs($admin)->deleteJson(route('people.access.unlink', [$person, $account]))->assertOk();

    expect($account->fresh()->access_token)->toBe($token);
    $this->get(route('access.show', $token))->assertRedirect();
});

it('404s an unlink whose person and account are not linked', function () {
    $mine = personWithEmail('meu@leomadeiras.com.br');
    $theirs = personWithEmail('deles@leomadeiras.com.br');
    $admin = accessAdmin();

    $this->actingAs($admin)->postJson(route('people.access.store', $mine), ['role' => 'viewer'])->assertOk();
    $this->actingAs($admin)->postJson(route('people.access.store', $theirs), ['role' => 'viewer'])->assertOk();

    $otherAccount = $theirs->fresh()->user;

    $this->actingAs($admin)
        ->deleteJson(route('people.access.unlink', [$mine, $otherAccount]))
        ->assertNotFound();

    expect($theirs->fresh()->user_id)->toBe($otherAccount->id);
});

it('refuses an EDITOR the unlink — it is access management, not curation', function () {
    $person = personWithEmail();
    $admin = accessAdmin();
    $this->actingAs($admin)->postJson(route('people.access.store', $person), ['role' => 'viewer'])->assertOk();

    $account = $person->fresh()->user;

    $this->actingAs(User::factory()->create(['role' => UserRole::Writer->value]))
        ->deleteJson(route('people.access.unlink', [$person, $account]))
        ->assertForbidden();

    expect($person->fresh()->user_id)->toBe($account->id);
});

it('offers both verbs on the card, each naming the account it acts on', function () {
    // They read as opposites of two different gestures, so the card has to say
    // which is which before either is pressed.
    $person = personWithEmail();
    $admin = accessAdmin();
    $this->actingAs($admin)->postJson(route('people.access.store', $person), ['role' => 'viewer'])->assertOk();

    $content = $this->actingAs($admin)->get(route('people.show', $person->fresh()))->assertOk()->getContent();

    expect($content)->toContain('Desvincular conta')
        ->and($content)->toContain('Remover acesso')
        // Both confirms name the e-mail: which account is about to stop working
        // is exactly what was missing from the screen.
        ->and($content)->toContain('Desvincular fulano@leomadeiras.com.br')
        ->and($content)->toContain('A conta fulano@leomadeiras.com.br deixa de funcionar');
});

it('reaches the unlink the way the button actually calls it', function () {
    $person = personWithEmail();
    $admin = accessAdmin();
    $this->actingAs($admin)->postJson(route('people.access.store', $person), ['role' => 'viewer'])->assertOk();

    $account = $person->fresh()->user;

    $this->actingAs($admin)
        ->postJson(route('people.access.unlink', [$person, $account]), ['_method' => 'DELETE'])
        ->assertOk();

    expect($person->fresh()->user_id)->toBeNull()
        ->and(User::find($account->id))->not->toBeNull();
});

it('offers the orphan as a CREDENTIAL, never as a second person', function () {
    // The confusion that started this: the picker was labelled with
    // `users.name`, so choosing one read as linking a person to another person.
    // An account is an e-mail and a role.
    $orphan = User::factory()->create([
        'role'  => UserRole::Admin->value,
        'name'  => 'Admin adminovitch :)',
        'email' => 'seed@leomadeiras.com.br',
    ]);
    $person = personWithEmail('quem@leomadeiras.com.br');

    $content = $this->actingAs(accessAdmin())->get(route('people.show', $person))->assertOk()->getContent();

    $option = str($content)->after('id="person-link-account"')->before('</select>')->toString();

    expect($option)->toContain('seed@leomadeiras.com.br')
        ->and($option)->toContain($orphan->role->label())
        ->and($option)->not->toContain('Admin adminovitch');
});

it('leads a roster row with the e-mail and names whose account it is', function () {
    $admin = accessAdmin();
    $person = personWithEmail();
    $this->actingAs($admin)->postJson(route('people.access.store', $person), ['role' => 'viewer'])->assertOk();

    $content = $this->actingAs($admin)->get(route('people.accounts'))->assertOk()->getContent();
    $row = str($content)->after('people-accounts-slot')->before('Convidar por e-mail')->toString();

    // The e-mail comes first in the row; the person is the answer underneath it.
    expect(strpos($row, $person->email))->toBeLessThan(strpos($row, $person->name));
});
