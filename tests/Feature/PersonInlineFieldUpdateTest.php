<?php

use App\Enums\ContactType;
use App\Enums\UserRole;
use App\Models\Company;
use App\Models\Contact;
use App\Models\Person;
use App\Models\User;
use Illuminate\Foundation\Testing\LazilyRefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;

uses(LazilyRefreshDatabase::class);

function personFieldAdmin(): User
{
    return User::factory()->create(['role' => UserRole::Admin->value]);
}

it('updates a single field inline from the detail header, returning only that slot', function () {
    $person = Person::factory()->create(['phone' => '(11) 1111-1111']);

    $response = $this->actingAs(personFieldAdmin())
        ->patchJson(route('people.field.update', $person), ['phone' => '(11) 99999-0000'])
        ->assertOk()
        ->assertJson(['type' => 'success']);

    // The detail page's own slots only — no people-index-slot / ResultsCount /
    // FilterChips exist on it for the catalog widgets to land in. Notes ride
    // along because the same endpoint serves the notes card.
    $ids = collect($response->json('updatableSlots'))->pluck('id');
    expect($ids)->toEqual(collect(['person-detail-header-slot', 'person-notes-slot']));

    $slot = collect($response->json('updatableSlots'))->firstWhere('id', 'person-detail-header-slot');
    expect($slot['content'])->toContain('(11) 99999-0000');

    expect($person->fresh()->phone)->toBe('(11) 99999-0000');
});

it('clears a nullable field back to null', function () {
    $person = Person::factory()->create(['job_title' => 'Analista']);

    $this->actingAs(personFieldAdmin())
        ->patchJson(route('people.field.update', $person), ['job_title' => null])
        ->assertOk();

    expect($person->fresh()->job_title)->toBeNull();
});

it('normalises an emptied field to null instead of an empty string', function () {
    $person = Person::factory()->create(['email' => 'a@b.com']);

    $this->actingAs(personFieldAdmin())
        ->patchJson(route('people.field.update', $person), ['email' => ''])
        ->assertOk();

    expect($person->fresh()->email)->toBeNull();
});

it('reassigns the company from the header select', function () {
    $person = Person::factory()->create();
    $company = Company::factory()->create(['name' => 'Leo Madeiras']);

    $response = $this->actingAs(personFieldAdmin())
        ->patchJson(route('people.field.update', $person), ['company_id' => $company->id])
        ->assertOk();

    expect($person->fresh()->company_id)->toBe($company->id);

    // The freshly rendered slot must show the new company, not the relation
    // that was loaded before the update.
    $slot = collect($response->json('updatableSlots'))->firstWhere('id', 'person-detail-header-slot');
    expect($slot['content'])->toContain('Leo Madeiras');
});

it('rejects emptying the name, which is not nullable', function () {
    $person = Person::factory()->create(['name' => 'Cecília']);

    $this->actingAs(personFieldAdmin())
        ->patchJson(route('people.field.update', $person), ['name' => ''])
        ->assertStatus(422);

    expect($person->fresh()->name)->toBe('Cecília');
});

it('rejects an invalid email', function () {
    $person = Person::factory()->create(['email' => 'valid@example.com']);

    $this->actingAs(personFieldAdmin())
        ->patchJson(route('people.field.update', $person), ['email' => 'not-an-email'])
        ->assertStatus(422);

    expect($person->fresh()->email)->toBe('valid@example.com');
});

it('keeps the slug when the name is renamed inline, so the page URL survives', function () {
    $person = Person::factory()->create(['name' => 'Cecília', 'slug' => 'cecilia-leo-madeiras']);

    $this->actingAs(personFieldAdmin())
        ->patchJson(route('people.field.update', $person), ['name' => 'Cecília Souza'])
        ->assertOk();

    expect($person->fresh()->slug)->toBe('cecilia-leo-madeiras');
});

it('replaces the photo from the header', function () {
    Storage::fake('public');

    $person = Person::factory()->create(['photo_path' => null]);

    // Two things the browser does that `patchJson()` can't express: the file
    // goes as multipart (so `patch()`, not `patchJson()`), and the module asks
    // for JSON via the header (so a rejection comes back as this app's 422
    // JSON shape instead of a redirect). The browser also has to send it as
    // POST + `_method=PATCH`, since PHP only fills $_FILES on POST; the test
    // client builds a real PATCH request, so it reaches the same route.
    $this->actingAs(personFieldAdmin())
        ->patch(
            route('people.field.update', $person),
            ['photo'  => UploadedFile::fake()->image('foto.png')],
            ['Accept' => 'application/json'],
        )
        ->assertOk();

    $path = $person->fresh()->photo_path;
    expect($path)->toStartWith('photos/');
    Storage::disk('public')->assertExists($path);
});

it('rejects a photo the client-side check would also refuse', function () {
    Storage::fake('public');

    $person = Person::factory()->create(['photo_path' => null]);

    // `{message, title, type}` — this app reformats every ValidationException
    // JSON response and has no `errors` key, so `assertJsonValidationErrors()`
    // would never work here.
    $response = $this->actingAs(personFieldAdmin())
        ->patch(
            route('people.field.update', $person),
            ['photo'  => UploadedFile::fake()->create('planilha.pdf', 10, 'application/pdf')],
            ['Accept' => 'application/json'],
        )
        ->assertStatus(422)
        ->assertJson(['type' => 'warning']);

    expect($response->json('message'))->toContain('imagem');

    expect($person->fresh()->photo_path)->toBeNull();
});

it('retypes one additional contact value', function () {
    $person = Person::factory()->create();
    $contact = Contact::factory()->for($person)->create([
        'type'  => ContactType::Whatsapp,
        'value' => '(11) 0000-0000',
    ]);

    $this->actingAs(personFieldAdmin())
        ->patchJson(route('people.contacts.update', [$person, $contact]), ['value' => '(11) 98888-7777'])
        ->assertOk();

    expect($contact->fresh()->value)->toBe('(11) 98888-7777');
});

it('404s a contact that belongs to someone else (scoped binding)', function () {
    $person = Person::factory()->create();
    $otherContact = Contact::factory()->for(Person::factory())->create();

    $this->actingAs(personFieldAdmin())
        ->patchJson(route('people.contacts.update', [$person, $otherContact]), ['value' => 'invadido'])
        ->assertNotFound();

    expect($otherContact->fresh()->value)->not->toBe('invadido');
});

it('refuses to blank out a contact value (removal belongs to the panel)', function () {
    $person = Person::factory()->create();
    $contact = Contact::factory()->for($person)->create(['value' => 'a@b.com']);

    $this->actingAs(personFieldAdmin())
        ->patchJson(route('people.contacts.update', [$person, $contact]), ['value' => ''])
        ->assertStatus(422);

    expect($contact->fresh()->value)->toBe('a@b.com');
});

it('forbids a non-admin from editing a field inline', function () {
    $person = Person::factory()->create(['phone' => '(11) 1111-1111']);

    $this->actingAs(User::factory()->create()) // viewer
        ->patchJson(route('people.field.update', $person), ['phone' => '(11) 99999-0000'])
        ->assertForbidden();

    expect($person->fresh()->phone)->toBe('(11) 1111-1111');
});

it('shows blank editable fields as a placeholder, so there is something to click', function () {
    $person = Person::factory()->create([
        'job_title'  => null,
        'email'      => null,
        'phone'      => null,
        'company_id' => null,
    ]);

    $this->actingAs(personFieldAdmin())
        ->get(route('people.show', $person))
        ->assertOk()
        ->assertSee('data-ak-inline-edit', false)
        ->assertSeeText('Adicionar cargo')
        ->assertSeeText('Definir empresa')
        ->assertSeeText('Não informado');
});

it('opens an editor that draws no box: the read wash stays as its ground, with a rule instead of a border', function () {
    $person = Person::factory()->for(Company::factory())->create(['job_title' => 'Analista', 'notes' => 'nota']);

    $html = $this->actingAs(personFieldAdmin())
        ->get(route('people.show', $person))
        ->assertOk()
        ->getContent();

    // One control per type, since `$chrome` reaches all three through different
    // x-forms components. Named by field, not just by tag: the first `<input>`
    // on the page is the photo's hidden file input, which deliberately carries
    // no `$chrome` at all (its editor is the upload tile, not a text field).
    $tags = collect(['input' => 'job_title', 'select' => 'company_id', 'textarea' => 'notes'])
        ->map(function (string $field, string $tag) use ($html) {
            preg_match('/<' . $tag . '[^>]*data-ak-inline-edit-field="' . $field . '"[^>]*>/', $html, $found);

            return $found[0] ?? '';
        });

    foreach ($tags as $tag => $markup) {
        expect($markup)->not->toBe('', "no editable {$tag} rendered on the person page");

        // No box: the wash of read mode is the ground, and the only line is the
        // 1.5px rule under the text (an inset shadow — a border would add
        // height and shove the text up as the editor opens).
        expect($markup)
            ->toContain('!border-0')
            ->toContain('!bg-[var(--ie-wash)]')
            ->toContain('!shadow-[inset_0_-1.5px_0_0_var(--ie-rule)]')
            ->toContain('focus:!shadow-[inset_0_-1.5px_0_0_var(--ie-rule)]');

        // The old framed chrome, which this replaced: a white fill or a border
        // colour here means someone reinstated the box.
        expect($markup)
            ->not->toContain('!bg-surface')
            ->not->toContain('!border-line')
            ->not->toContain('!shadow-sm');
    }

    // The editor's own horizontal padding, the offset that cancels it, and read
    // mode's wash padding are ONE measurement in three places — the value only
    // lands where it was read while all three agree.
    expect($tags['input'])->toContain('!px-1.5');   // the editor's padding
    expect($html)
        ->toContain('-ml-1.5')                      // the row offset that cancels it
        ->toContain('-mx-1.5');                     // read mode's own wash padding

    // With no border, a blank field would open as a caret alone on the page:
    // the italic placeholder is what's left to anchor the eye.
    expect($tags['input'])->toContain('placeholder:!italic');
});

it('retypes a value in the typography it was read in, and never lets read mode own its display', function () {
    $person = Person::factory()->create(['name' => 'Ana Silva']);

    $html = $this->actingAs(personFieldAdmin())
        ->get(route('people.show', $person))
        ->assertOk()
        ->getContent();

    // The name is read as a 28px display heading, so it's retyped as one. This
    // asserts the whole `inputClass` chain reaches the actual element:
    // x-ui.inline-edit → x-ui.inline-edit-field → x-forms.input.
    expect($html)->toContain('!text-[28px]');

    // The pencil is the single-click way in, and the only one a keyboard or a
    // finger has — so it has to be a real button carrying the open hook, not
    // the decorative icon it started as.
    expect($html)->toMatch('/<button[^>]*data-ak-inline-edit-open[^>]*aria-label="Editar Nome"/');

    // `inline-edit.js` hides read mode by toggling Tailwind's `hidden`. A
    // `display` utility of its own makes that a coin toss decided by stylesheet
    // order — which it lost once, leaving read mode on screen UNDER the open
    // editor. Read mode's own width/shape belong on the span inside it.
    preg_match_all('/<div data-ak-inline-edit-read.*?class="([^"]*)"/s', $html, $matches);

    expect($matches[1])->not->toBeEmpty();

    foreach ($matches[1] as $classes) {
        expect($classes)->not->toMatch('/\b(block|inline-block|flex|inline-flex|grid|table)\b/');
    }
});

it('leaves the header read-only for a viewer, with blank fields simply absent', function () {
    $person = Person::factory()->create(['job_title' => null, 'email' => null, 'phone' => null]);

    $response = $this->actingAs(User::factory()->create()) // viewer
        ->get(route('people.show', $person))
        ->assertOk();

    $response->assertDontSee('data-ak-inline-edit', false);
    $response->assertDontSee('Adicionar cargo');
    $response->assertDontSee('Não informado');
});
