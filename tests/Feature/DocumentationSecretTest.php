<?php

use App\Actions\Documentation\RevealPageSecret;
use App\Enums\UserRole;
use App\Models\DocumentationPage;
use App\Models\Notebook;
use App\Models\User;
use App\Services\DocumentationSearchService;
use App\Support\Documentation\SecretText;
use Illuminate\Foundation\Testing\LazilyRefreshDatabase;
use Illuminate\Support\Facades\DB;
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

function secretUser(UserRole $role = UserRole::Member): User
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

/**
 * The ids a page's values are stored under, in document order.
 *
 * @return list<string>
 */
function secretIdsOf(?string $markdown): array
{
    preg_match_all('/\{% secret id="([a-z0-9]{8})" %\}/', (string) $markdown, $matches);

    return $matches[1];
}

/** One paragraph per value — `a: ALPHA`, `b: BRAVO`, … */
function secretParagraphs(array $values): string
{
    return implode("\n\n", array_map(
        fn (string $value, int $i): string => chr(97 + $i) . ': {% secret %}' . $value . '{% endsecret %}',
        $values,
        array_keys($values),
    ));
}

/*
|--------------------------------------------------------------------------
| SecretText — a value is addressed by its id
|--------------------------------------------------------------------------
*/

it('stores every value under an id and round-trips it through a mask', function () {
    $page = secretPage();
    [$first, $second] = secretIdsOf($page->documentation);

    expect(SecretText::values($page->documentation))
        ->toBe([1 => SECRET_VALUE, 2 => 'Bearer 9f1c-prd'])
        ->and($first)->not->toBe($second);

    $masked = SecretText::mask($page->documentation);

    expect($masked)->toContain("{% secret %}[[SECRET-{$first}]]{% endsecret %}")
        ->and($masked)->toContain("{% secret %}[[SECRET-{$second}]]{% endsecret %}")
        ->and($masked)->not->toContain(SECRET_VALUE)
        ->and($masked)->not->toContain('id="')
        ->and(SecretText::restore($masked, $page->documentation))->toBe($page->documentation);
});

it('masks idempotently — the assistant masks the editor content, which is masked already', function () {
    $masked = SecretText::mask(secretPage()->documentation);

    expect(SecretText::mask($masked))->toBe($masked);
});

it('restores a marker by its ID, wherever the text moved it to', function () {
    $stored = 'a {% secret id="aaaaaaa1" %}um{% endsecret %} b {% secret id="aaaaaaa2" %}dois{% endsecret %}';
    // The assistant reordered the page and dropped a paragraph — the markers
    // still mean the values they were handed out for.
    $incoming = "b {% secret %}[[SECRET-aaaaaaa2]]{% endsecret %}\n\na {% secret %}[[SECRET-aaaaaaa1]]{% endsecret %}";

    expect(SecretText::restore($incoming, $stored))
        ->toBe("b {% secret id=\"aaaaaaa2\" %}dois{% endsecret %}\n\na {% secret id=\"aaaaaaa1\" %}um{% endsecret %}");
});

it('keeps an invented marker as a visible dead lock instead of guessing a value for it', function () {
    $stored = 'x {% secret id="aaaaaaa1" %}um{% endsecret %}';

    $restored = SecretText::restore('x {% secret %}[[SECRET-zzzzzzzz]]{% endsecret %}', $stored);

    expect(SecretText::values($restored))->toBe([1 => '[[SECRET-zzzzzzzz]]'])
        ->and(secretIdsOf($restored))->toHaveCount(1)
        ->and(secretIdsOf($restored)[0])->not->toBe('aaaaaaa1');
});

it('re-wraps a marker that lost its construct instead of exposing the value', function () {
    // The shape that got here: a protected value inside inline code came back
    // from the editor as `` `[[SECRET-…]]` ``, the `{% secret %}` flattened
    // away by the serializer's <code> branch. Resolving that would have written
    // the real value into the page in the clear.
    $stored = 'x `{% secret id="aaaaaaa1" %}Bearer 9f1c{% endsecret %}`';

    expect(SecretText::restore('x `[[SECRET-aaaaaaa1]]`', $stored))->toBe($stored);
});

it('does not double-wrap a marker that still has its construct', function () {
    $stored = 'x {% secret id="aaaaaaa1" %}um{% endsecret %}';

    expect(SecretText::restore('x {% secret %}[[SECRET-aaaaaaa1]]{% endsecret %}', $stored))->toBe($stored);
});

it('never reads a marker inside a value it has just put back', function () {
    // A value that IS a marker's text — what the ordinal bug left behind in the
    // pages it had already scrambled. Reading it a second time would nest a
    // construct inside a construct.
    $stored = '{% secret id="aaaaaaa1" %}[[SECRET-2]]{% endsecret %} {% secret id="aaaaaaa2" %}dois{% endsecret %}';

    expect(SecretText::restore(SecretText::mask($stored), $stored))->toBe($stored);
});

it('still resolves a NUMBERED marker handed out before values had ids', function () {
    // An editor tab left open across the deploy holds `[[SECRET-n]]`, and so
    // does an assistant draft written before it. Without this, that tab's next
    // autosave would turn every value on the page into a dead lock.
    $stored = 'a {% secret id="aaaaaaa1" %}um{% endsecret %} b {% secret id="aaaaaaa2" %}dois{% endsecret %}';

    expect(SecretText::restore('{% secret %}[[SECRET-2]]{% endsecret %} `[[SECRET-1]]`', $stored))
        ->toBe('{% secret id="aaaaaaa2" %}dois{% endsecret %} `{% secret id="aaaaaaa1" %}um{% endsecret %}`');
});

it('keeps a protected value protected across a save from inside inline code', function () {
    $notebook = Notebook::factory()->create(['secret_code' => 'X6h2dG']);
    $page = DocumentationPage::factory()->for($notebook)->create([
        'documentation' => "Header: <code>{% secret %}Bearer 9f1c{% endsecret %}</code>\n",
    ]);
    [$id] = secretIdsOf($page->documentation);

    // The shape the editor posted before the serializer was fixed: the marker
    // inside backticks, its construct gone.
    $this->actingAs(secretUser(UserRole::Admin))
        ->patchJson(route('notebooks.pages.update', [$notebook, $page]), [
            'documentation' => "Header: `[[SECRET-{$id}]]`\n",
        ])
        ->assertOk();

    $fresh = $page->fresh();

    expect($fresh->documentation)->toContain("{% secret id=\"{$id}\" %}Bearer 9f1c{% endsecret %}");

    // And the page still renders a lock rather than the value.
    $html = $this->actingAs(secretUser())
        ->get(route('notebooks.pages.edit', [$notebook, $page]))
        ->assertOk()
        ->getContent();

    expect($html)->not->toContain('Bearer 9f1c')
        ->and($html)->toContain("data-ak-secret=\"{$id}\"");
});

it('treats a deleted marker as a deleted value', function () {
    $stored = 'x {% secret id="aaaaaaa1" %}um{% endsecret %}';

    expect(SecretText::restore('x sem valor', $stored))->toBe('x sem valor');
});

it('stores every protected value with an id, whoever writes the page', function () {
    // Not through the editor: the model gives them their ids on any write,
    // which is what lets mask() rely on every stored value having one.
    $page = DocumentationPage::factory()->create([
        'documentation' => 'a {% secret %}um{% endsecret %} b {% secret %}dois{% endsecret %}',
    ]);
    $ids = secretIdsOf($page->documentation);

    expect($ids)->toHaveCount(2)
        ->and($ids[0])->not->toBe($ids[1]);

    $page->update(['documentation' => $page->documentation . ' c {% secret %}três{% endsecret %}']);

    // The ids already there are kept; only the new value gets one.
    $after = secretIdsOf($page->fresh()->documentation);

    expect($after)->toHaveCount(3)
        ->and(array_slice($after, 0, 2))->toBe($ids);
});

/*
|--------------------------------------------------------------------------
| Reading a page — nobody is handed the plaintext
|--------------------------------------------------------------------------
*/

it('shows a viewer a lock per value and no plaintext anywhere on the page', function () {
    $page = secretPage();
    [$first, $second] = secretIdsOf($page->documentation);

    $response = $this->actingAs(secretUser())
        ->get(route('notebooks.pages.edit', [$page->notebook, $page]))
        ->assertOk();

    $html = $response->getContent();

    expect($html)->not->toContain(SECRET_VALUE)
        ->and($html)->not->toContain('Bearer 9f1c-prd')
        // One lock per value, carrying its id — what the reveal endpoint is
        // addressed by.
        ->and($html)->toContain("data-ak-secret=\"{$first}\"")
        ->and($html)->toContain("data-ak-secret=\"{$second}\"")
        ->and(substr_count($html, 'valor protegido'))->toBeGreaterThanOrEqual(2);
});

it('hands an ADMIN markers too — the editor never receives the values in bulk', function () {
    $page = secretPage();
    [$first] = secretIdsOf($page->documentation);

    $html = $this->actingAs(secretUser(UserRole::Admin))
        ->get(route('notebooks.pages.edit', [$page->notebook, $page]))
        ->assertOk()
        ->getContent();

    expect($html)->not->toContain(SECRET_VALUE)
        ->and($html)->toContain("[[SECRET-{$first}]]")
        // …and is told it will not be asked for a code.
        ->and($html)->toContain('data-ak-secret-unlocked="1"');
});

it('hands an EDITOR markers and does not tell it the code is unnecessary', function () {
    $page = secretPage();
    [$first] = secretIdsOf($page->documentation);

    $html = $this->actingAs(User::factory()->editor()->create())
        ->get(route('notebooks.pages.edit', [$page->notebook, $page]))
        ->assertOk()
        ->getContent();

    expect($html)->not->toContain(SECRET_VALUE)
        ->and($html)->toContain("[[SECRET-{$first}]]")
        ->and($html)->not->toContain('data-ak-secret-unlocked');
});

it('keeps the plaintext out of the public page AND out of its "Copiar Markdown" source', function () {
    $page = secretPage();
    $page->notebook->update(['public_token' => 'tok123456789']);
    [$first] = secretIdsOf($page->documentation);

    $html = $this->get(route('public.docs.page', ['tok123456789', $page->slug]))
        ->assertOk()
        ->getContent();

    // The textarea behind "Copiar Markdown" is the half that is easy to forget:
    // the rendered HTML shows locks while the raw Markdown sat beside it.
    expect($html)->not->toContain(SECRET_VALUE)
        ->and($html)->toContain("[[SECRET-{$first}]]")
        ->and($html)->toContain("data-ak-secret=\"{$first}\"");
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
        ->postJson(route('notebooks.pages.secrets', [$page->notebook, $page, secretIdsOf($page->documentation)[0]]), ['code' => 'X6h2dG'])
        ->assertOk()
        ->assertJson(['value' => SECRET_VALUE]);
});

it('still answers an ORDINAL, for a page rendered before values had ids', function () {
    $page = secretPage();

    $this->actingAs(secretUser(UserRole::Admin))
        ->postJson(route('notebooks.pages.secrets', [$page->notebook, $page, 2]))
        ->assertOk()
        ->assertJson(['value' => 'Bearer 9f1c-prd']);
});

it('reveals the value a reader clicked even after somebody added one above it', function () {
    // The cost numbering by position used to accept: a reader holding the page
    // while it was edited unlocked the wrong value of the two.
    $page = secretPage();
    [, $second] = secretIdsOf($page->documentation);

    $this->actingAs(secretUser())
        ->get(route('notebooks.pages.edit', [$page->notebook, $page]))
        ->assertSee("data-ak-secret=\"{$second}\"", false);

    $this->actingAs(secretUser(UserRole::Admin))
        ->patchJson(route('notebooks.pages.update', [$page->notebook, $page]), [
            'documentation' => "Topo: {% secret %}valor-novo{% endsecret %}\n\n" . SecretText::mask($page->documentation),
        ])
        ->assertOk();

    $this->actingAs(secretUser())
        ->postJson(route('notebooks.pages.secrets', [$page->notebook, $page, $second]), ['code' => 'X6h2dG'])
        ->assertOk()
        ->assertJson(['value' => 'Bearer 9f1c-prd']);
});

it('reveals a value to an admin with no code at all', function () {
    $page = secretPage();

    $this->actingAs(secretUser(UserRole::Admin))
        ->postJson(route('notebooks.pages.secrets', [$page->notebook, $page, secretIdsOf($page->documentation)[1]]))
        ->assertOk()
        ->assertJson(['value' => 'Bearer 9f1c-prd']);
});

it('refuses an EDITOR without the code — writing a page is not reading its values', function () {
    $page = secretPage();

    $this->actingAs(User::factory()->editor()->create())
        ->postJson(route('notebooks.pages.secrets', [$page->notebook, $page, secretIdsOf($page->documentation)[0]]))
        ->assertStatus(422)
        ->assertJsonMissing(['value' => SECRET_VALUE]);
});

it('refuses a wrong code and says how many attempts are left', function () {
    $page = secretPage();

    $response = $this->actingAs(secretUser())
        ->postJson(route('notebooks.pages.secrets', [$page->notebook, $page, secretIdsOf($page->documentation)[0]]), ['code' => 'nope00'])
        ->assertStatus(422);

    expect($response->json('message'))->toContain('Restam 4 tentativas');
});

it('does not fold case on the code — this is authorisation, not search', function () {
    $page = secretPage();

    $this->actingAs(secretUser())
        ->postJson(route('notebooks.pages.secrets', [$page->notebook, $page, secretIdsOf($page->documentation)[0]]), ['code' => 'x6h2dg'])
        ->assertStatus(422);
});

it('404s on an address the page does not have, without spending an attempt', function () {
    $page = secretPage();
    $user = secretUser();

    // An id this page has no value under (deleted under the reader), and an
    // ordinal past its last value.
    foreach (['zzzzzzzz', 9] as $address) {
        $this->actingAs($user)
            ->postJson(route('notebooks.pages.secrets', [$page->notebook, $page, $address]), ['code' => 'nope00'])
            ->assertNotFound();
    }

    // The right code still works immediately afterwards — nothing was counted.
    $this->actingAs($user)
        ->postJson(route('notebooks.pages.secrets', [$page->notebook, $page, secretIdsOf($page->documentation)[0]]), ['code' => 'X6h2dG'])
        ->assertOk();
});

it('locks a reader out for twelve hours after five wrong codes', function () {
    // Frozen: the window is what is under test, and a suite that straddles a
    // real boundary would pass or fail on where the clock happens to be
    // (§ Testing in AGENTS.md).
    $this->freezeTime();

    $page = secretPage();
    $user = secretUser();
    $url = route('notebooks.pages.secrets', [$page->notebook, $page, secretIdsOf($page->documentation)[0]]);

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
    $url = route('notebooks.pages.secrets', [$page->notebook, $page, secretIdsOf($page->documentation)[0]]);

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
    $url = route('notebooks.pages.secrets', [$page->notebook, $page, secretIdsOf($page->documentation)[0]]);
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
    $url = route('public.docs.secrets', ['tok123456789', $page->slug, secretIdsOf($page->documentation)[0]]);

    $this->postJson($url)->assertStatus(422);

    $this->postJson($url, ['code' => 'X6h2dG'])
        ->assertOk()
        ->assertJson(['value' => SECRET_VALUE]);
});

it('never bypasses the code on the magic link, even for a signed-in admin', function () {
    $page = secretPage();
    $page->notebook->update(['public_token' => 'tok123456789']);

    $this->actingAs(secretUser(UserRole::Admin))
        ->postJson(route('public.docs.secrets', ['tok123456789', $page->slug, secretIdsOf($page->documentation)[0]]))
        ->assertStatus(422);
});

it('refuses a token that does not own the page', function () {
    $page = secretPage();
    $other = Notebook::factory()->create(['public_token' => 'tok123456789']);

    expect($other->pages()->count())->toBe(0);

    $this->postJson(route('public.docs.secrets', ['tok123456789', $page->slug, secretIdsOf($page->documentation)[0]]), ['code' => 'X6h2dG'])
        ->assertNotFound();
});

it('says so when the caderno has no code, instead of reading as a wrong guess', function () {
    $page = secretPage();
    // Not reachable through the app — a caderno always gets one — but a row
    // restored from an older dump can look like this.
    $page->notebook->forceFill(['secret_code' => null])->save();

    $response = $this->actingAs(secretUser())
        ->postJson(route('notebooks.pages.secrets', [$page->notebook, $page, secretIdsOf($page->documentation)[0]]), ['code' => 'X6h2dG'])
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

    $this->actingAs(User::factory()->editor()->create())
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
    [$first, $second] = secretIdsOf($page->documentation);

    $this->actingAs(secretUser(UserRole::Admin))
        ->patchJson(route('notebooks.pages.update', [$page->notebook, $page]), [
            'documentation' => "x {% secret %}novo-valor{% endsecret %} y {% secret %}[[SECRET-{$second}]]{% endsecret %}",
        ])
        ->assertOk();

    $stored = $page->fresh()->documentation;
    [$typed] = secretIdsOf($stored);

    // The typed value is a NEW value, under an id of its own — the one it
    // replaced is gone, not re-pointed.
    expect($stored)->toBe("x {% secret id=\"{$typed}\" %}novo-valor{% endsecret %} y {% secret id=\"{$second}\" %}Bearer 9f1c-prd{% endsecret %}")
        ->and($typed)->not->toBe($first);
});

/*
|--------------------------------------------------------------------------
| One editing session is MANY saves
|--------------------------------------------------------------------------
|
| The bug these exist for. The editor is handed its markers once, when the
| page opens, and autosave writes the page ~1.2s after every pause in typing.
| While values were addressed by position, adding, deleting or moving one
| renumbered the page on the first save and left the editor holding the old
| numbers, so every save after it put values back in the wrong places: a value
| added above two others was the page's ONLY value three saves later.
|
*/

dataset('changes that renumber a page', [
    'a value added above the others' => [
        ['ALPHA', 'BRAVO'],
        fn (string $editor): string => "token: {% secret %}NOVO{% endsecret %}\n\n" . $editor,
        ['NOVO', 'ALPHA', 'BRAVO'],
    ],
    'a value deleted above the others' => [
        ['ALPHA', 'BRAVO', 'CHARLIE'],
        fn (string $editor): string => preg_replace('/^[^\n]*\n\n/', '', $editor, 1),
        ['BRAVO', 'CHARLIE'],
    ],
    'two values swapped' => [
        ['ALPHA', 'BRAVO'],
        fn (string $editor): string => implode("\n\n", array_reverse(explode("\n\n", $editor))),
        ['BRAVO', 'ALPHA'],
    ],
]);

it('keeps every value in place across the saves of one editing session', function (array $before, Closure $change, array $after) {
    $notebook = Notebook::factory()->create();
    $page = DocumentationPage::factory()->for($notebook)->create(['documentation' => secretParagraphs($before)]);
    $admin = secretUser(UserRole::Admin);

    // What the editor holds from here on: the page as it was handed out, with
    // the change made in it. Each save adds a keystroke and sends it again.
    $editor = $change(SecretText::mask($page->documentation));

    foreach (range(1, 4) as $save) {
        $this->actingAs($admin)
            ->patchJson(route('notebooks.pages.update', [$notebook, $page]), [
                'documentation' => $editor . "\n\nedição {$save}",
            ])
            ->assertOk();

        $stored = $page->fresh()->documentation;

        expect(array_values(SecretText::values($stored)))->toBe($after, "after save {$save}")
            ->and($stored)->not->toContain('[[SECRET-');
    }
})->with('changes that renumber a page');

it('answers a save with the id each value went under, so the editor can hold markers only', function () {
    $page = secretPage();
    [$first, $second] = secretIdsOf($page->documentation);
    $admin = secretUser(UserRole::Admin);
    $editor = "Novo: {% secret %}tok-novo{% endsecret %}\n\n" . SecretText::mask($page->documentation);

    $ids = $this->actingAs($admin)
        ->patchJson(route('notebooks.pages.update', [$page->notebook, $page]), ['documentation' => $editor])
        ->assertOk()
        ->json('secretIds');

    $stored = $page->fresh()->documentation;

    // One id per `{% secret %}` posted, in order: the typed value's new one,
    // then the two the markers already carried.
    expect($ids)->toBe(secretIdsOf($stored))
        ->and(array_slice($ids, 1))->toBe([$first, $second]);

    // What docs-editor.js then does: the typed value becomes its marker. The
    // next save sends markers only, and writes exactly what is stored.
    $adopted = str_replace('{% secret %}tok-novo{% endsecret %}', "{% secret %}[[SECRET-{$ids[0]}]]{% endsecret %}", $editor);

    $this->actingAs($admin)
        ->patchJson(route('notebooks.pages.update', [$page->notebook, $page]), ['documentation' => $adopted])
        ->assertOk()
        ->assertJson(['secretIds' => $ids]);

    expect($page->fresh()->documentation)->toBe($stored);
});

it('never derives an id from the value — a typed guess says nothing about a stored one', function () {
    // If the id were a function of the value, an editor who may not read a
    // value could type a guess, save, and compare the two markers.
    $page = secretPage();
    [$first] = secretIdsOf($page->documentation);
    $guess = '{% secret %}' . SECRET_VALUE . '{% endsecret %}';

    $ids = $this->actingAs(User::factory()->editor()->create())
        ->patchJson(route('notebooks.pages.update', [$page->notebook, $page]), [
            'documentation' => SecretText::mask($page->documentation) . "\n\n{$guess} {$guess}",
        ])
        ->assertOk()
        ->json('secretIds');

    expect($ids)->toHaveCount(4)
        ->and($ids[0])->toBe($first)
        ->and([$ids[2], $ids[3]])->not->toContain($first)
        ->and($ids[2])->not->toBe($ids[3]);
});

it('ignores an id the client posts', function () {
    $page = secretPage();
    [$first, $second] = secretIdsOf($page->documentation);

    $this->actingAs(User::factory()->editor()->create())
        ->patchJson(route('notebooks.pages.update', [$page->notebook, $page]), [
            'documentation' => "{% secret id=\"{$first}\" %}outro-valor{% endsecret %} {% secret %}[[SECRET-{$second}]]{% endsecret %}",
        ])
        ->assertOk();

    $stored = $page->fresh()->documentation;

    // The posted text is a new value, not the old id pointed somewhere else.
    expect(SecretText::valueOf($stored, $first))->toBeNull()
        ->and(SecretText::valueOf($stored, $second))->toBe('Bearer 9f1c-prd')
        ->and(SecretText::values($stored))->toBe([1 => 'outro-valor', 2 => 'Bearer 9f1c-prd']);
});

it('gives the values already stored an id, without touching who updated the page or when', function () {
    $migration = require database_path('migrations/2026_10_16_175559_assign_ids_to_documentation_secrets.php');
    $page = DocumentationPage::factory()->create();
    $legacy = 'a {% secret %}um{% endsecret %} b {% secret %}dois{% endsecret %}';

    // A row as it looked before ids: written straight to the table, past the
    // model hook that would give it ids today.
    DB::table('documentation_pages')->where('id', $page->id)->update([
        'documentation' => $legacy,
        'updated_at'    => '2026-01-01 00:00:00',
    ]);
    $row = fn () => DB::table('documentation_pages')->where('id', $page->id)->first();

    $migration->up();

    expect(SecretText::values($row()->documentation))->toBe([1 => 'um', 2 => 'dois'])
        ->and(secretIdsOf($row()->documentation))->toHaveCount(2)
        ->and($row()->updated_at)->toBe('2026-01-01 00:00:00');

    // Idempotent: a value that has an id keeps it.
    $once = $row()->documentation;
    $migration->up();
    expect($row()->documentation)->toBe($once);

    // The code a rollback returns to knows only `{% secret %}` — a construct
    // still carrying an id would render as text, value and all.
    $migration->down();
    expect($row()->documentation)->toBe($legacy);
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
    $this->actingAs(User::factory()->editor()->create())
        ->get(route('notebooks.pages.edit', [$notebook, $page]))
        ->assertOk()
        ->assertDontSee($notebook->secret_code, false);
});

it('rotates the code for an admin and refuses an editor', function () {
    $notebook = Notebook::factory()->create(['secret_code' => 'X6h2dG']);

    $this->actingAs(User::factory()->editor()->create())
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
    $url = route('notebooks.pages.secrets', [$page->notebook, $page, secretIdsOf($page->documentation)[0]]);

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
