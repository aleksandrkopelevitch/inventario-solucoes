<?php

use App\Enums\UserRole;
use App\Mail\UserInvitationMail;
use App\Models\Person;
use App\Models\User;
use Illuminate\Foundation\Testing\LazilyRefreshDatabase;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Password;

uses(LazilyRefreshDatabase::class);

it('lets an admin read the accounts roster and refuses everyone else', function () {
    // The modal this replaces was `users.index` inside `#main-modal`. It is a
    // real page in the Pessoas module now, so it can be linked and bookmarked —
    // and it stays admin-only.
    $admin = User::factory()->create(['role' => UserRole::Admin->value]);

    $this->actingAs($admin)
        ->get(route('people.accounts'))
        ->assertOk()
        ->assertSee('Quem tem acesso')
        ->assertSee($admin->email);

    foreach ([UserRole::Writer, UserRole::Viewer] as $role) {
        $this->actingAs(User::factory()->create(['role' => $role->value]))
            ->get(route('people.accounts'))
            ->assertForbidden();
    }
});

it('forbids a non-admin from viewing or inviting users', function () {
    $viewer = User::factory()->create();

    $this->actingAs($viewer)->get(route('people.accounts'))->assertForbidden();
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

    expect($response->json('updatableSlots.0.id'))->toBe('people-accounts-slot');

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
        ->and($response->json('updatableSlots.0.id'))->toBe('people-accounts-slot');
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

it('offers the role select on other rows of the roster and withholds it on your own', function () {
    $admin = User::factory()->create(['role' => UserRole::Admin->value, 'name' => 'Eu Mesmo']);
    User::factory()->create(['role' => UserRole::Admin->value, 'name' => 'Outro Admin']);
    User::factory()->create(['role' => UserRole::Viewer->value, 'name' => 'Alguem Viewer']);

    $content = $this->actingAs($admin)->get(route('people.accounts'))->assertOk()->getContent();

    // The URL is compared JSON-encoded because that is how the component
    // carries it — `json_encode` escapes the slashes.
    $actionOf = fn (User $user) => trim(json_encode(route('users.update', $user)), '"');

    expect(substr_count($content, 'data-ak-inline-edit='))->toBe(2)
        ->and($content)->toContain('Eu Mesmo')
        ->and($content)->toContain($actionOf(User::where('name', 'Alguem Viewer')->sole()))
        ->and($content)->not->toContain($actionOf($admin));
});

/*
|--------------------------------------------------------------------------
| An invite creates the CATALOG ROW too
|--------------------------------------------------------------------------
|
| Until 2026-09-01 this screen was the app's orphan factory: it made an account
| and no Person, ever. That is what left "vincular uma conta que já existe" as a
| routine gesture — and a picker of accounts labelled by `users.name` reads as
| linking a person to another person, which is not a question the app should ask.
| The two tables stay separate (105 of 108 catalog rows have no e-mail at all,
| and `people` is writable by an editor while an account is the admin's); what
| changed is that a NEW account arrives with its human attached.
|
*/

it('creates the catalog row with the account and links the two', function () {
    Mail::fake();

    $response = $this->actingAs(User::factory()->create(['role' => UserRole::Admin->value]))
        ->postJson(route('users.store'), [
            'name'  => 'Marina Duarte',
            'email' => 'marina.duarte@leomadeiras.com.br',
            'role'  => 'writer',
        ])->assertOk();

    $account = User::firstWhere('email', 'marina.duarte@leomadeiras.com.br');
    $person = Person::firstWhere('email', 'marina.duarte@leomadeiras.com.br');

    expect($person)->not->toBeNull()
        ->and($person->name)->toBe('Marina Duarte')
        ->and($person->slug)->toBe('marina-duarte')
        ->and($person->user_id)->toBe($account->id)
        // No orphan left behind — which is the whole point.
        ->and(User::whereDoesntHave('person')->whereKey($account->id)->exists())->toBeFalse()
        ->and($response->json('message'))->toContain('Marina Duarte');
});

it('reuses the person already filed under that e-mail, whatever its case', function () {
    Mail::fake();
    // The ordinary case: the invited person is already in the catalog as a
    // contact. A second row for the same human would be the wrong fix.
    $existing = Person::factory()->create([
        'name'  => 'Rafael Nogueira',
        'email' => 'Rafael.Nogueira@leomadeiras.com.br',
    ]);

    $this->actingAs(User::factory()->create(['role' => UserRole::Admin->value]))
        ->postJson(route('users.store'), [
            'name'  => 'Rafael N.',
            'email' => 'rafael.nogueira@leomadeiras.com.br',
            'role'  => 'viewer',
        ])->assertOk();

    expect(Person::withEmail('rafael.nogueira@leomadeiras.com.br')->count())->toBe(1)
        ->and($existing->fresh()->user_id)->not->toBeNull()
        // The catalog's own copy of the name wins — it was curated.
        ->and($existing->fresh()->name)->toBe('Rafael Nogueira');
});

it('refuses an invite whose person already holds a different account', function () {
    Mail::fake();
    $admin = User::factory()->create(['role' => UserRole::Admin->value]);

    // Linked to an account with ANOTHER e-mail, which `unique:users,email`
    // cannot catch — exactly the state that produced the lockout: linking would
    // silently orphan the account this person already has.
    $person = Person::factory()->create(['name' => 'Joana Prado', 'email' => 'joana@leomadeiras.com.br']);
    $held = User::factory()->create(['email' => 'jp-antigo@leomadeiras.com.br']);
    $person->user()->associate($held)->save();

    $response = $this->actingAs($admin)
        ->postJson(route('users.store'), [
            'name'  => 'Joana Prado',
            'email' => 'joana@leomadeiras.com.br',
            'role'  => 'viewer',
        ])->assertStatus(422);

    expect($response->json('message'))->toContain('Joana Prado')
        ->and($person->fresh()->user_id)->toBe($held->id)
        ->and(User::where('email', 'joana@leomadeiras.com.br')->exists())->toBeFalse();

    Mail::assertNothingQueued();
});

it('keeps an invited person off the reserved slugs', function () {
    Mail::fake();
    // `people/accounts` is a real route, so a person slugged `accounts` would be
    // unreachable at their own URL. The rule lives on the model now, because
    // this door creates people too.
    $this->actingAs(User::factory()->create(['role' => UserRole::Admin->value]))
        ->postJson(route('users.store'), [
            'name'  => 'Accounts',
            'email' => 'accounts@leomadeiras.com.br',
            'role'  => 'viewer',
        ])->assertOk();

    expect(Person::firstWhere('email', 'accounts@leomadeiras.com.br')->slug)->not->toBe('accounts');
});

it('matches nobody when asked for the person with no e-mail', function () {
    // 105 of 108 catalog rows have no e-mail. The folding macros read an empty
    // value as "no constraint", so without the guard this scope answers with an
    // arbitrary person — and its caller attaches an account to whoever that is.
    Person::factory()->count(3)->create(['email' => null]);

    expect(Person::withEmail(null)->exists())->toBeFalse()
        ->and(Person::withEmail('')->exists())->toBeFalse()
        ->and(Person::withEmail('   ')->exists())->toBeFalse();
});
