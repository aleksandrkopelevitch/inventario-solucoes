<?php

use App\Actions\Auth\ResolveEntraUser;
use App\Enums\UserRole;
use App\Exceptions\EntraSignInRejected;
use App\Http\Controllers\Auth\EntraController;
use App\Models\Notebook;
use App\Models\User;
use App\Support\Auth\EntraSso;
use Illuminate\Foundation\Testing\LazilyRefreshDatabase;
use Laravel\Socialite\Two\User as SocialiteUser;

uses(LazilyRefreshDatabase::class);

beforeEach(function () {
    config([
        'services.azure.enabled'         => true,
        'services.azure.client_id'       => 'client-id',
        'services.azure.client_secret'   => 'client-secret',
        'services.azure.tenant'          => '00000000-0000-0000-0000-000000000000',
        'services.azure.redirect'        => 'https://isol.test/auth/entra/callback',
        'services.azure.allowed_domains' => ['leomadeiras.com.br'],
    ]);
});

/** A profile shaped like Microsoft Graph's `/v1.0/me`, which is what the driver maps. */
function entraProfile(array $raw = []): SocialiteUser
{
    $raw = array_merge([
        'id'                => 'oid-' . fake()->uuid(),
        'displayName'       => 'Ana Souza',
        'userPrincipalName' => 'ana.souza@leomadeiras.com.br',
        'mail'              => 'ana.souza@leomadeiras.com.br',
    ], $raw);

    return (new SocialiteUser)->setRaw($raw)->map([
        'id'    => $raw['id'],
        'name'  => $raw['displayName'],
        'email' => $raw['userPrincipalName'],
    ]);
}

function signIn(SocialiteUser $profile): User
{
    return app(ResolveEntraUser::class)->handle($profile);
}

it('provisions a first-time signer as a reader and nothing more', function () {
    $user = signIn(entraProfile());

    expect($user->role)->toBe(UserRole::Reader)
        ->and($user->email)->toBe('ana.souza@leomadeiras.com.br')
        ->and($user->name)->toBe('Ana Souza')
        ->and($user->entra_id)->not->toBeEmpty();
});

it('links an account that already existed and never touches its role', function () {
    $existing = User::factory()->create([
        'email' => 'ana.souza@leomadeiras.com.br',
        'role'  => UserRole::Admin,
    ]);

    $user = signIn(entraProfile());

    // The match that happens exactly once. Without it, an admin invited last
    // year arrives as a brand-new reader with their submissions and chats
    // stranded on the old row.
    expect($user->is($existing))->toBeTrue()
        ->and($user->role)->toBe(UserRole::Admin)
        ->and($user->entra_id)->not->toBeEmpty();
});

it('matches on the e-mail whatever its case', function () {
    $existing = User::factory()->create(['email' => 'Ana.Souza@leomadeiras.com.br']);

    expect(signIn(entraProfile())->is($existing))->toBeTrue();
});

it('recognises them by oid afterwards, even when the mailbox is renamed', function () {
    $first = signIn(entraProfile(['id' => 'oid-stable']));

    $renamed = signIn(entraProfile([
        'id'                => 'oid-stable',
        'mail'              => 'ana.pereira@leomadeiras.com.br',
        'userPrincipalName' => 'ana.pereira@leomadeiras.com.br',
    ]));

    // One account, carrying the new address — the `oid` is the half that
    // survives a surname change.
    expect($renamed->is($first))->toBeTrue()
        ->and($renamed->email)->toBe('ana.pereira@leomadeiras.com.br')
        ->and(User::count())->toBe(1);
});

it('refuses a mailbox outside the allowed domains', function () {
    signIn(entraProfile([
        'mail'              => 'someone@gmail.com',
        'userPrincipalName' => 'someone@gmail.com',
    ]));
})->throws(EntraSignInRejected::class, 'não é uma conta Leo Madeiras');

it('refuses a domain that merely ENDS with an allowed one', function () {
    // `str_ends_with` on the whole address would pass this, and it is a
    // different company.
    signIn(entraProfile([
        'mail'              => 'attacker@evil-leomadeiras.com.br',
        'userPrincipalName' => 'attacker@evil-leomadeiras.com.br',
    ]));
})->throws(EntraSignInRejected::class);

it('refuses a guest account, whose UPN carries a Leo domain', function () {
    // The case the domain check cannot see: a B2B guest is a legitimate member
    // of the Leo tenant and does not work at Leo.
    signIn(entraProfile([
        'mail'              => null,
        'userPrincipalName' => 'someone_partner.com#EXT#@leomadeiras.onmicrosoft.com',
    ]));
})->throws(EntraSignInRejected::class, 'convidado');

it('refuses a revoked account instead of quietly restoring it', function () {
    $user = User::factory()->create(['email' => 'ana.souza@leomadeiras.com.br']);
    $user->delete();

    // The rule this protects: "remover acesso" would mean nothing at all for
    // anybody who still has a mailbox.
    signIn(entraProfile());
})->throws(EntraSignInRejected::class, 'removido');

it('prefers the real mailbox over the sign-in name', function () {
    $user = signIn(entraProfile([
        'mail'              => 'ana.souza@leomadeiras.com.br',
        'userPrincipalName' => 'asouza-adm@leomadeiras.com.br',
    ]));

    expect($user->email)->toBe('ana.souza@leomadeiras.com.br');
});

it('falls back to the sign-in name when the directory has no mailbox', function () {
    $user = signIn(entraProfile([
        'mail'              => null,
        'userPrincipalName' => 'ana.souza@leomadeiras.com.br',
    ]));

    expect($user->email)->toBe('ana.souza@leomadeiras.com.br');
});

it('refuses everything when the allow-list is empty', function () {
    // A mistyped ENTRA_ALLOWED_DOMAINS must fail closed.
    config(['services.azure.allowed_domains' => []]);

    expect(EntraSso::allows('ana.souza@leomadeiras.com.br'))->toBeFalse();
});

it('reports itself unconfigured while any piece is missing', function () {
    expect(EntraSso::configured())->toBeTrue();

    config(['services.azure.client_secret' => null]);
    expect(EntraSso::configured())->toBeFalse();

    config(['services.azure.client_secret' => 'client-secret', 'services.azure.enabled' => false]);
    expect(EntraSso::configured())->toBeFalse();
});

it('offers the Microsoft button on the login screen only when configured', function () {
    $this->get(route('login.create'))->assertOk()->assertSee('Entrar com Microsoft');

    config(['services.azure.enabled' => false]);

    $this->get(route('login.create'))->assertOk()->assertDontSee('Entrar com Microsoft');
});

it('404s every SSO route while SSO is switched off', function () {
    config(['services.azure.enabled' => false]);

    $this->get(route('entra.redirect'))->assertNotFound();
    $this->get(route('entra.silent'))->assertNotFound();
    $this->get(route('entra.callback'))->assertNotFound();
});

it('tries a silent sign-on once for a guest reaching the knowledge base', function () {
    $notebook = Notebook::factory()->published()->create();

    $this->get(route('docs.notebook', $notebook))
        ->assertRedirect(route('entra.silent'))
        // Where they were going, so the callback can put them back.
        ->assertSessionHas('url.intended', route('docs.notebook', $notebook));
});

it('never tries a second time, because prompt=none fails by redirecting back', function () {
    $notebook = Notebook::factory()->published()->create();

    // The attempt is spent. A second one here is an infinite loop between two
    // hosts, which is the single worst failure this feature can have.
    $this->withSession([EntraController::SILENT_ATTEMPTED => true])
        ->get(route('docs.notebook', $notebook))
        ->assertRedirect(route('login.create'));
});

it('never redirects an AJAX or JSON request off to Microsoft', function () {
    $notebook = Notebook::factory()->published()->create();

    // A fetch would follow the redirect and try to parse Microsoft's login
    // page as JSON.
    $this->getJson(route('docs.search', $notebook) . '?q=x')
        ->assertUnauthorized();
});

it('sends a guest who is not signed in with Microsoft back to the login screen, quietly', function () {
    // What `prompt=none` answers with when there is no session: an error in the
    // query string, and no `code` at all.
    $response = $this->withSession(['entra.silent_in_flight' => true])
        ->get(route('entra.callback', ['error' => 'login_required', 'error_description' => 'AADSTS50058']));

    // No flash: they never asked for anything, so there is nothing to report.
    $response->assertRedirect(route('login.create'))->assertSessionMissing('error');
});

it('says so out loud when an INTERACTIVE sign-in is refused', function () {
    $response = $this->get(route('entra.callback', ['error' => 'access_denied']));

    $response->assertRedirect(route('login.create'))->assertSessionHas('error');
});
