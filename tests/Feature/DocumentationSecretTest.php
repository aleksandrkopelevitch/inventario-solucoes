<?php

use App\Actions\Documentation\RevealPageSecret;
use App\Enums\UserRole;
use App\Models\DocumentationPage;
use App\Models\Notebook;
use App\Models\User;
use App\Services\DocumentationSearchService;
use App\Support\Documentation\SecretText;
use Illuminate\Foundation\Testing\LazilyRefreshDatabase;
use Illuminate\Support\Facades\RateLimiter;

uses(LazilyRefreshDatabase::class);

/*
|--------------------------------------------------------------------------
| Protected values in documentation — `{% secret %}` … `{% endsecret %}`
|--------------------------------------------------------------------------
|
| The one rule every test here is a restatement of: the plaintext leaves the
| server through App\Actions\Documentation\RevealPageSecret and nowhere else.
| Fixtures are synthetic (same reasoning as LiteralVaultTest's) — a test suite
| is not a place to keep a credential.
|
*/

/** The value used throughout, shaped like the header that motivated the feature. */
const SECRET_VALUE = 'Basic c2ItbGVvOnMzY3IzdC1xYXMtdG9rZW4=';

function secretUser(UserRole $role = UserRole::Viewer): User
{
    return User::factory()->create(['role' => $role->value]);
}

/** A caderno with a known code and one page carrying two protected values. */
function secretPage(string $code = 'X6h2dG'): DocumentationPage
{
    $notebook = Notebook::factory()->create(['secret_code' => $code]);

    return DocumentationPage::factory()->for($notebook)->create([
        'documentation' => "# API\n\n"
            . 'O header é {% secret %}' . SECRET_VALUE . "{% endsecret %} para QAS.\n\n"
            . "```http\nAuthorization: {% secret %}Bearer 9f1c-prd{% endsecret %}\n```\n",
    ]);
}

/*
|--------------------------------------------------------------------------
| SecretText — the ordinals every surface agrees on
|--------------------------------------------------------------------------
*/

it('numbers protected values in document order and round-trips them through a mask', function () {
    $page = secretPage();

    expect(SecretText::values($page->documentation))
        ->toBe([1 => SECRET_VALUE, 2 => 'Bearer 9f1c-prd']);

    $masked = SecretText::mask($page->documentation);

    expect($masked)->toContain('{% secret %}[[SECRET-1]]{% endsecret %}')
        ->and($masked)->toContain('{% secret %}[[SECRET-2]]{% endsecret %}')
        ->and($masked)->not->toContain(SECRET_VALUE)
        ->and(SecretText::restore($masked, $page->documentation))->toBe($page->documentation);
});

it('restores a marker by its NUMBER, wherever the text moved it to', function () {
    $stored = 'a {% secret %}um{% endsecret %} b {% secret %}dois{% endsecret %}';
    // The assistant reordered the page and dropped a paragraph — the markers
    // still mean the values they were handed out for.
    $incoming = "b {% secret %}[[SECRET-2]]{% endsecret %}\n\na {% secret %}[[SECRET-1]]{% endsecret %}";

    expect(SecretText::restore($incoming, $stored))
        ->toBe("b {% secret %}dois{% endsecret %}\n\na {% secret %}um{% endsecret %}");
});

it('leaves an invented marker alone instead of guessing a value for it', function () {
    $stored = 'x {% secret %}um{% endsecret %}';

    expect(SecretText::restore('x {% secret %}[[SECRET-7]]{% endsecret %}', $stored))
        ->toBe('x {% secret %}[[SECRET-7]]{% endsecret %}');
});

it('treats a deleted marker as a deleted value', function () {
    $stored = 'x {% secret %}um{% endsecret %}';

    expect(SecretText::restore('x sem valor', $stored))->toBe('x sem valor');
});

/*
|--------------------------------------------------------------------------
| Reading a page — nobody is handed the plaintext
|--------------------------------------------------------------------------
*/

it('shows a viewer a lock per value and no plaintext anywhere on the page', function () {
    $page = secretPage();

    $response = $this->actingAs(secretUser())
        ->get(route('notebooks.pages.edit', [$page->notebook, $page]))
        ->assertOk();

    $html = $response->getContent();

    expect($html)->not->toContain(SECRET_VALUE)
        ->and($html)->not->toContain('Bearer 9f1c-prd')
        // One lock per value, numbered — the ordinal is what the reveal
        // endpoint is addressed by.
        ->and($html)->toContain('data-ak-secret="1"')
        ->and($html)->toContain('data-ak-secret="2"')
        ->and(substr_count($html, 'valor protegido'))->toBeGreaterThanOrEqual(2);
});

it('hands an ADMIN markers too — the editor never receives the values in bulk', function () {
    $page = secretPage();

    $html = $this->actingAs(secretUser(UserRole::Admin))
        ->get(route('notebooks.pages.edit', [$page->notebook, $page]))
        ->assertOk()
        ->getContent();

    expect($html)->not->toContain(SECRET_VALUE)
        ->and($html)->toContain('[[SECRET-1]]')
        // …and is told it will not be asked for a code.
        ->and($html)->toContain('data-ak-secret-unlocked="1"');
});

it('hands an EDITOR markers and does not tell it the code is unnecessary', function () {
    $page = secretPage();

    $html = $this->actingAs(secretUser(UserRole::Writer))
        ->get(route('notebooks.pages.edit', [$page->notebook, $page]))
        ->assertOk()
        ->getContent();

    expect($html)->not->toContain(SECRET_VALUE)
        ->and($html)->toContain('[[SECRET-1]]')
        ->and($html)->not->toContain('data-ak-secret-unlocked');
});

it('keeps the plaintext out of the public page AND out of its "Copiar Markdown" source', function () {
    $page = secretPage();
    $page->notebook->update(['public_token' => 'tok123456789']);

    $html = $this->get(route('public.docs.page', ['tok123456789', $page->slug]))
        ->assertOk()
        ->getContent();

    // The textarea behind "Copiar Markdown" is the half that is easy to forget:
    // the rendered HTML shows locks while the raw Markdown sat beside it.
    expect($html)->not->toContain(SECRET_VALUE)
        ->and($html)->toContain('[[SECRET-1]]')
        ->and($html)->toContain('data-ak-secret="1"');
});

it('keeps the plaintext out of the search index and its snippets', function () {
    $page = secretPage();
    $page->notebook->update(['public_token' => 'tok123456789']);

    $payload = app(DocumentationSearchService::class)->search($page->notebook, 'header');

    expect(json_encode($payload))->not->toContain(SECRET_VALUE);
});

/*
|--------------------------------------------------------------------------
| Revealing one value
|--------------------------------------------------------------------------
*/

it('reveals a value to anyone who types the caderno code', function () {
    $page = secretPage();

    $this->actingAs(secretUser())
        ->postJson(route('notebooks.pages.secrets', [$page->notebook, $page, 1]), ['code' => 'X6h2dG'])
        ->assertOk()
        ->assertJson(['value' => SECRET_VALUE]);
});

it('reveals a value to an admin with no code at all', function () {
    $page = secretPage();

    $this->actingAs(secretUser(UserRole::Admin))
        ->postJson(route('notebooks.pages.secrets', [$page->notebook, $page, 2]))
        ->assertOk()
        ->assertJson(['value' => 'Bearer 9f1c-prd']);
});

it('refuses an EDITOR without the code — writing a page is not reading its values', function () {
    $page = secretPage();

    $this->actingAs(secretUser(UserRole::Writer))
        ->postJson(route('notebooks.pages.secrets', [$page->notebook, $page, 1]))
        ->assertStatus(422)
        ->assertJsonMissing(['value' => SECRET_VALUE]);
});

it('refuses a wrong code and says how many attempts are left', function () {
    $page = secretPage();

    $response = $this->actingAs(secretUser())
        ->postJson(route('notebooks.pages.secrets', [$page->notebook, $page, 1]), ['code' => 'nope00'])
        ->assertStatus(422);

    expect($response->json('message'))->toContain('Restam 4 tentativas');
});

it('does not fold case on the code — this is authorisation, not search', function () {
    $page = secretPage();

    $this->actingAs(secretUser())
        ->postJson(route('notebooks.pages.secrets', [$page->notebook, $page, 1]), ['code' => 'x6h2dg'])
        ->assertStatus(422);
});

it('404s on an ordinal the page does not have, without spending an attempt', function () {
    $page = secretPage();
    $user = secretUser();

    $this->actingAs($user)
        ->postJson(route('notebooks.pages.secrets', [$page->notebook, $page, 9]), ['code' => 'nope00'])
        ->assertNotFound();

    // The right code still works immediately afterwards — nothing was counted.
    $this->actingAs($user)
        ->postJson(route('notebooks.pages.secrets', [$page->notebook, $page, 1]), ['code' => 'X6h2dG'])
        ->assertOk();
});

it('locks a reader out for twelve hours after five wrong codes', function () {
    // Frozen: the window is what is under test, and a suite that straddles a
    // real boundary would pass or fail on where the clock happens to be
    // (§ Testing in AGENTS.md).
    $this->freezeTime();

    $page = secretPage();
    $user = secretUser();
    $url = route('notebooks.pages.secrets', [$page->notebook, $page, 1]);

    foreach (range(1, RevealPageSecret::MAX_ATTEMPTS) as $attempt) {
        $this->actingAs($user)->postJson($url, ['code' => 'nope00'])->assertStatus(422);
    }

    // The sixth is refused before the code is even compared — and the RIGHT
    // code is refused too, which is the whole point of a lockout.
    $response = $this->actingAs($user)->postJson($url, ['code' => 'X6h2dG'])->assertStatus(429);
    expect($response->json('message'))->toContain('12h');

    $this->travel(11)->hours();
    $this->actingAs($user)->postJson($url, ['code' => 'X6h2dG'])->assertStatus(429);

    $this->travel(2)->hours();
    $this->actingAs($user)->postJson($url, ['code' => 'X6h2dG'])->assertOk();
});

it('lets a correct code buy back the failed attempts before it', function () {
    $this->freezeTime();

    $page = secretPage();
    $user = secretUser();
    $url = route('notebooks.pages.secrets', [$page->notebook, $page, 1]);

    $this->actingAs($user)->postJson($url, ['code' => 'nope00'])->assertStatus(422);
    $this->actingAs($user)->postJson($url, ['code' => 'nope00'])->assertStatus(422);
    $this->actingAs($user)->postJson($url, ['code' => 'X6h2dG'])->assertOk();

    // Back to a full allowance: four more failures must not reach the lockout.
    foreach (range(1, 4) as $attempt) {
        $this->actingAs($user)->postJson($url, ['code' => 'nope00'])->assertStatus(422);
    }

    $this->actingAs($user)->postJson($url, ['code' => 'X6h2dG'])->assertOk();
});

it('counts one reader\'s failures against that reader alone', function () {
    $this->freezeTime();

    $page = secretPage();
    $url = route('notebooks.pages.secrets', [$page->notebook, $page, 1]);
    $clumsy = secretUser();

    foreach (range(1, RevealPageSecret::MAX_ATTEMPTS) as $attempt) {
        $this->actingAs($clumsy)->postJson($url, ['code' => 'nope00'])->assertStatus(422);
    }

    $this->actingAs($clumsy)->postJson($url, ['code' => 'X6h2dG'])->assertStatus(429);
    $this->actingAs(secretUser())->postJson($url, ['code' => 'X6h2dG'])->assertOk();
});

it('reveals a value on the magic link with the code, and refuses it without', function () {
    $page = secretPage();
    $page->notebook->update(['public_token' => 'tok123456789']);
    $url = route('public.docs.secrets', ['tok123456789', $page->slug, 1]);

    $this->postJson($url)->assertStatus(422);

    $this->postJson($url, ['code' => 'X6h2dG'])
        ->assertOk()
        ->assertJson(['value' => SECRET_VALUE]);
});

it('never bypasses the code on the magic link, even for a signed-in admin', function () {
    $page = secretPage();
    $page->notebook->update(['public_token' => 'tok123456789']);

    $this->actingAs(secretUser(UserRole::Admin))
        ->postJson(route('public.docs.secrets', ['tok123456789', $page->slug, 1]))
        ->assertStatus(422);
});

it('refuses a token that does not own the page', function () {
    $page = secretPage();
    $other = Notebook::factory()->create(['public_token' => 'tok123456789']);

    expect($other->pages()->count())->toBe(0);

    $this->postJson(route('public.docs.secrets', ['tok123456789', $page->slug, 1]), ['code' => 'X6h2dG'])
        ->assertNotFound();
});

it('says so when the caderno has no code, instead of reading as a wrong guess', function () {
    $page = secretPage();
    // Not reachable through the app — a caderno always gets one — but a row
    // restored from an older dump can look like this.
    $page->notebook->forceFill(['secret_code' => null])->save();

    $response = $this->actingAs(secretUser())
        ->postJson(route('notebooks.pages.secrets', [$page->notebook, $page, 1]), ['code' => 'X6h2dG'])
        ->assertStatus(422);

    expect($response->json('message'))->toContain('ainda não tem um código');
});

/*
|--------------------------------------------------------------------------
| Saving a page — the values survive an editor that never saw them
|--------------------------------------------------------------------------
*/

it('puts the real values back when an editor saves the masked text', function () {
    $page = secretPage();
    $masked = SecretText::mask($page->documentation);

    $this->actingAs(secretUser(UserRole::Writer))
        ->patchJson(route('notebooks.pages.update', [$page->notebook, $page]), [
            // What the editor round-trips: markers, plus a real edit around them.
            'documentation' => str_replace('para QAS', 'para o ambiente QAS', $masked),
        ])
        ->assertOk();

    $fresh = $page->fresh();

    expect($fresh->documentation)->toContain(SECRET_VALUE)
        ->and($fresh->documentation)->toContain('Bearer 9f1c-prd')
        ->and($fresh->documentation)->toContain('para o ambiente QAS')
        ->and($fresh->documentation)->not->toContain('[[SECRET-');
});

it('lets whoever is editing replace a protected value by typing over its marker', function () {
    $page = secretPage();

    $this->actingAs(secretUser(UserRole::Admin))
        ->patchJson(route('notebooks.pages.update', [$page->notebook, $page]), [
            'documentation' => 'x {% secret %}novo-valor{% endsecret %} y {% secret %}[[SECRET-2]]{% endsecret %}',
        ])
        ->assertOk();

    expect($page->fresh()->documentation)
        ->toBe('x {% secret %}novo-valor{% endsecret %} y {% secret %}Bearer 9f1c-prd{% endsecret %}');
});

/*
|--------------------------------------------------------------------------
| The code itself
|--------------------------------------------------------------------------
*/

it('gives every new caderno a code, and shows it only to an admin', function () {
    $notebook = Notebook::factory()->create();
    expect($notebook->secret_code)->toHaveLength(Notebook::SECRET_CODE_LENGTH);

    $page = DocumentationPage::factory()->for($notebook)->create();

    $this->actingAs(secretUser(UserRole::Admin))
        ->get(route('notebooks.pages.edit', [$notebook, $page]))
        ->assertOk()
        ->assertSee($notebook->secret_code, false);

    // An editor can write every page in this caderno and still must not be
    // handed the string that unlocks its values.
    $this->actingAs(secretUser(UserRole::Writer))
        ->get(route('notebooks.pages.edit', [$notebook, $page]))
        ->assertOk()
        ->assertDontSee($notebook->secret_code, false);
});

it('rotates the code for an admin and refuses an editor', function () {
    $notebook = Notebook::factory()->create(['secret_code' => 'X6h2dG']);

    $this->actingAs(secretUser(UserRole::Writer))
        ->postJson(route('notebooks.secret-code', $notebook))
        ->assertForbidden();

    expect($notebook->fresh()->secret_code)->toBe('X6h2dG');

    $this->actingAs(secretUser(UserRole::Admin))
        ->postJson(route('notebooks.secret-code', $notebook))
        ->assertOk()
        ->assertJson(['type' => 'success']);

    expect($notebook->fresh()->secret_code)->not->toBe('X6h2dG')
        ->and($notebook->fresh()->secret_code)->toHaveLength(Notebook::SECRET_CODE_LENGTH);
});

it('stops the old code working once it is rotated', function () {
    $page = secretPage();
    $url = route('notebooks.pages.secrets', [$page->notebook, $page, 1]);

    $page->notebook->rotateSecretCode();

    $this->actingAs(secretUser())->postJson($url, ['code' => 'X6h2dG'])->assertStatus(422);
    $this->actingAs(secretUser())
        ->postJson($url, ['code' => $page->notebook->fresh()->secret_code])
        ->assertOk();
});

it('refuses `secret-code` as a page slug, since a real route owns that segment', function () {
    $notebook = Notebook::factory()->create();

    $this->actingAs(secretUser(UserRole::Admin))
        ->postJson(route('notebooks.pages.store', $notebook), ['title' => 'Secret code'])
        ->assertOk();

    expect($notebook->pages()->sole()->slug)->not->toBe('secret-code');
});

afterEach(function () {
    RateLimiter::clear('docs-secret');
});
