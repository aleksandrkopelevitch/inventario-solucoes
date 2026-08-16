<?php

use App\Enums\ContactType;
use App\Enums\PersonSolutionRole;
use App\Enums\UserRole;
use App\Models\Company;
use App\Models\Contact;
use App\Models\Person;
use App\Models\Solution;
use App\Models\User;
use Illuminate\Foundation\Testing\LazilyRefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;

uses(LazilyRefreshDatabase::class);

function inlineRelationsAdmin(): User
{
    return User::factory()->create(['role' => UserRole::Admin->value]);
}

function slotIds(array $response): array
{
    return collect($response['updatableSlots'])->pluck('id')->all();
}

function slotContent(array $response, string $id): string
{
    return collect($response['updatableSlots'])->firstWhere('id', $id)['content'];
}

// ── Contacts: add / remove ───────────────────────────────────────────────

it('adds an additional contact from the header', function () {
    $person = Person::factory()->create();

    $response = $this->actingAs(inlineRelationsAdmin())
        ->postJson(route('people.contacts.store', $person), [
            'type'  => ContactType::Whatsapp->value,
            'value' => '(11) 98888-7777',
        ])
        ->assertOk()
        ->assertJson(['type' => 'success']);

    $contact = $person->contacts()->sole();
    expect($contact->type)->toBe(ContactType::Whatsapp);
    expect($contact->value)->toBe('(11) 98888-7777');

    // The header re-renders with it, so the new row shows up without a reload.
    expect(slotIds($response->json()))->toEqual(['person-detail-header-slot']);
    expect(slotContent($response->json(), 'person-detail-header-slot'))->toContain('(11) 98888-7777');
});

it('rejects an added contact with no value or an unknown type', function () {
    $person = Person::factory()->create();

    $this->actingAs(inlineRelationsAdmin())
        ->postJson(route('people.contacts.store', $person), ['type' => ContactType::Email->value, 'value' => ''])
        ->assertStatus(422);

    $this->actingAs(inlineRelationsAdmin())
        ->postJson(route('people.contacts.store', $person), ['type' => 'carrier-pigeon', 'value' => 'x'])
        ->assertStatus(422);

    expect($person->contacts()->count())->toBe(0);
});

it('removes an additional contact from the header', function () {
    $person = Person::factory()->create();
    $contact = Contact::factory()->for($person)->create();

    $this->actingAs(inlineRelationsAdmin())
        ->deleteJson(route('people.contacts.destroy', [$person, $contact]))
        ->assertOk();

    $this->assertModelMissing($contact);
});

it('404s removing a contact that belongs to someone else (scoped binding)', function () {
    $person = Person::factory()->create();
    $otherContact = Contact::factory()->for(Person::factory())->create();

    $this->actingAs(inlineRelationsAdmin())
        ->deleteJson(route('people.contacts.destroy', [$person, $otherContact]))
        ->assertNotFound();

    $this->assertModelExists($otherContact);
});

it('forbids a non-admin from adding or removing a contact', function () {
    $person = Person::factory()->create();
    $contact = Contact::factory()->for($person)->create();
    $viewer = User::factory()->create();

    $this->actingAs($viewer)
        ->postJson(route('people.contacts.store', $person), ['type' => ContactType::Email->value, 'value' => 'a@b.com'])
        ->assertForbidden();

    $this->actingAs($viewer)
        ->deleteJson(route('people.contacts.destroy', [$person, $contact]))
        ->assertForbidden();

    expect($person->contacts()->count())->toBe(1);
});

// ── Notes ────────────────────────────────────────────────────────────────

it('saves notes from the notes card, preserving line breaks', function () {
    $person = Person::factory()->create(['notes' => null]);

    $response = $this->actingAs(inlineRelationsAdmin())
        ->patchJson(route('people.field.update', $person), ['notes' => "Primeira linha\nSegunda linha"])
        ->assertOk();

    expect($person->fresh()->notes)->toBe("Primeira linha\nSegunda linha");

    // The header AND the notes card come back — notes live in their own slot.
    expect(slotIds($response->json()))->toEqual(['person-detail-header-slot', 'person-notes-slot']);
    expect(slotContent($response->json(), 'person-notes-slot'))->toContain('Segunda linha');
});

it('clears notes back to null', function () {
    $person = Person::factory()->create(['notes' => 'algo']);

    $this->actingAs(inlineRelationsAdmin())
        ->patchJson(route('people.field.update', $person), ['notes' => null])
        ->assertOk();

    expect($person->fresh()->notes)->toBeNull();
});

it('shows the notes card to an admin with a placeholder, and hides it from a viewer with nothing to read', function () {
    $person = Person::factory()->create(['notes' => null]);

    $this->actingAs(inlineRelationsAdmin())
        ->get(route('people.show', $person))
        ->assertOk()
        ->assertSeeText('Anotações')
        ->assertSeeText('Adicionar anotações');

    $this->actingAs(User::factory()->create()) // viewer
        ->get(route('people.show', $person))
        ->assertOk()
        ->assertDontSeeText('Anotações');
});

// ── Systems: attach / re-role / detach ───────────────────────────────────

it('links a system with its role from the systems card', function () {
    $person = Person::factory()->create();
    $solution = Solution::factory()->create(['name' => 'SAP ECC']);

    $response = $this->actingAs(inlineRelationsAdmin())
        ->postJson(route('people.solutions.store', $person), [
            'solution_id' => $solution->id,
            'role'        => PersonSolutionRole::Manager->value,
        ])
        ->assertOk();

    expect($person->solutions()->sole()->pivot->role)->toBe(PersonSolutionRole::Manager->value);

    expect(slotIds($response->json()))->toEqual(['person-systems-slot']);
    $content = slotContent($response->json(), 'person-systems-slot');
    expect($content)->toContain('SAP ECC');
    expect($content)->toContain('Gestor');
});

it('refuses to link the same system twice', function () {
    $person = Person::factory()->create();
    $solution = Solution::factory()->create();
    $person->solutions()->attach($solution, ['role' => PersonSolutionRole::Technical->value]);

    $response = $this->actingAs(inlineRelationsAdmin())
        ->postJson(route('people.solutions.store', $person), [
            'solution_id' => $solution->id,
            'role'        => PersonSolutionRole::Support->value,
        ])
        ->assertStatus(422);

    expect($response->json('message'))->toContain('já está vinculada');
    expect($person->solutions()->count())->toBe(1);
});

it('re-roles an existing link from its badge', function () {
    $person = Person::factory()->create();
    $solution = Solution::factory()->create();
    $person->solutions()->attach($solution, ['role' => PersonSolutionRole::Technical->value]);

    $this->actingAs(inlineRelationsAdmin())
        ->patchJson(route('people.solutions.update', [$person, $solution]), [
            'role' => PersonSolutionRole::KeyUser->value,
        ])
        ->assertOk();

    expect($person->solutions()->sole()->pivot->role)->toBe(PersonSolutionRole::KeyUser->value);
});

it('rejects a role that is not in the enum', function () {
    $person = Person::factory()->create();
    $solution = Solution::factory()->create();
    $person->solutions()->attach($solution, ['role' => PersonSolutionRole::Technical->value]);

    $this->actingAs(inlineRelationsAdmin())
        ->patchJson(route('people.solutions.update', [$person, $solution]), ['role' => 'chefe-supremo'])
        ->assertStatus(422);

    expect($person->solutions()->sole()->pivot->role)->toBe(PersonSolutionRole::Technical->value);
});

it('re-points a link at another system from the row, carrying the role and is_primary over', function () {
    $person = Person::factory()->create();
    $old = Solution::factory()->create(['name' => 'Sistema antigo']);
    $new = Solution::factory()->create(['name' => 'Sistema novo']);
    $person->solutions()->attach($old, ['role' => PersonSolutionRole::KeyUser->value, 'is_primary' => true]);

    $response = $this->actingAs(inlineRelationsAdmin())
        ->patchJson(route('people.solutions.update', [$person, $old]), ['solution_id' => $new->id])
        ->assertOk();

    $link = $person->solutions()->sole();
    expect($link->id)->toBe($new->id);
    expect($link->pivot->role)->toBe(PersonSolutionRole::KeyUser->value);
    expect((bool) $link->pivot->is_primary)->toBeTrue();

    expect(slotContent($response->json(), 'person-systems-slot'))->toContain('Sistema novo');
});

it('refuses to re-point a link at a system this person is already linked to', function () {
    $person = Person::factory()->create();
    $one = Solution::factory()->create();
    $two = Solution::factory()->create();
    $person->solutions()->attach($one, ['role' => PersonSolutionRole::Technical->value]);
    // A different role, so the pivot's (person, solution, role) unique index
    // wouldn't catch this one on its own.
    $person->solutions()->attach($two, ['role' => PersonSolutionRole::Manager->value]);

    $response = $this->actingAs(inlineRelationsAdmin())
        ->patchJson(route('people.solutions.update', [$person, $one]), ['solution_id' => $two->id])
        ->assertStatus(422);

    expect($response->json('message'))->toContain('já está vinculada');
    expect($person->solutions()->count())->toBe(2);
});

it('404s editing a link this person does not have, instead of creating one (scoped binding)', function () {
    $person = Person::factory()->create();
    $unlinked = Solution::factory()->create();
    $target = Solution::factory()->create();

    $this->actingAs(inlineRelationsAdmin())
        ->patchJson(route('people.solutions.update', [$person, $unlinked]), ['solution_id' => $target->id])
        ->assertNotFound();

    expect($person->solutions()->count())->toBe(0);
});

it('unlinks a system', function () {
    $person = Person::factory()->create();
    $solution = Solution::factory()->create();
    $person->solutions()->attach($solution, ['role' => PersonSolutionRole::Technical->value]);

    $this->actingAs(inlineRelationsAdmin())
        ->deleteJson(route('people.solutions.destroy', [$person, $solution]))
        ->assertOk();

    expect($person->solutions()->count())->toBe(0);
    // The solution itself is a first-class record — only the link goes.
    $this->assertModelExists($solution);
});

it('forbids a non-admin from linking, re-roling or unlinking a system', function () {
    $person = Person::factory()->create();
    $solution = Solution::factory()->create();
    $person->solutions()->attach($solution, ['role' => PersonSolutionRole::Technical->value]);
    $viewer = User::factory()->create();

    $this->actingAs($viewer)
        ->postJson(route('people.solutions.store', $person), ['solution_id' => Solution::factory()->create()->id, 'role' => PersonSolutionRole::Support->value])
        ->assertForbidden();

    $this->actingAs($viewer)
        ->patchJson(route('people.solutions.update', [$person, $solution]), ['role' => PersonSolutionRole::Support->value])
        ->assertForbidden();

    $this->actingAs($viewer)
        ->deleteJson(route('people.solutions.destroy', [$person, $solution]))
        ->assertForbidden();

    expect($person->solutions()->sole()->pivot->role)->toBe(PersonSolutionRole::Technical->value);
});

it('offers only unlinked solutions in the systems picker', function () {
    $person = Person::factory()->create();
    $linked = Solution::factory()->create(['name' => 'Já vinculado']);
    Solution::factory()->create(['name' => 'Ainda livre']);
    $person->solutions()->attach($linked, ['role' => PersonSolutionRole::Technical->value]);

    $response = $this->actingAs(inlineRelationsAdmin())
        ->get(route('people.show', $person))
        ->assertOk();

    // Both names are on the page (the linked one as a row), so the assertion
    // has to be about being an <option>, not about the name appearing at all —
    // and about HOW MANY, since a row's own swap picker legitimately offers the
    // system it already points at (the select has to open on it).
    $html = $response->getContent();

    // The linked one: only in its own row's picker, never in the creator's.
    expect(substr_count($html, '>Já vinculado</option>'))->toBe(1);
    // The free one: offered by the row's swap picker AND by the creator.
    expect(substr_count($html, '>Ainda livre</option>'))->toBe(2);
});

// ── Linked records: the ↗ navigates, the text edits ──────────────────────

it('reaches a linked company and system through the ↗, leaving the text to the editor', function () {
    $company = Company::factory()->create(['name' => 'Leo Madeiras']);
    $person = Person::factory()->for($company)->create();
    $solution = Solution::factory()->create(['name' => 'SAP ECC']);
    Solution::factory()->create(); // something to re-point the link at
    $person->solutions()->attach($solution, ['role' => PersonSolutionRole::Technical->value]);

    $response = $this->actingAs(inlineRelationsAdmin())
        ->get(route('people.show', $person))
        ->assertOk();

    // Navigation lives on the icon…
    $response->assertSee('href="' . route('companies.show', $company) . '" data-ak-inline-edit-link', false);
    $response->assertSee('href="' . route('solutions.show', $solution) . '" data-ak-inline-edit-link', false);
    // …and the names themselves are editors, not links.
    $response->assertDontSee('<a href="' . route('companies.show', $company) . '" class', false);
    $response->assertDontSee('<a href="' . route('solutions.show', $solution) . '" class', false);
    $response->assertSee('data-ak-inline-edit-field="company_id"', false);
    $response->assertSee('data-ak-inline-edit-field="solution_id"', false);
});

it('keeps the ↗ for a viewer, who has no editor behind the text', function () {
    $company = Company::factory()->create();
    $person = Person::factory()->for($company)->create();
    $solution = Solution::factory()->create();
    $person->solutions()->attach($solution, ['role' => PersonSolutionRole::Technical->value]);

    $response = $this->actingAs(User::factory()->create()) // viewer
        ->get(route('people.show', $person))
        ->assertOk();

    $response->assertSee('href="' . route('companies.show', $company) . '" data-ak-inline-edit-link', false);
    $response->assertSee('href="' . route('solutions.show', $solution) . '" data-ak-inline-edit-link', false);
    $response->assertDontSee('data-ak-inline-edit-field="company_id"', false);
    $response->assertDontSee('data-ak-inline-edit-field="solution_id"', false);
});

it('leaves a row with nothing to re-point at as plain text, still reachable by the ↗', function () {
    $person = Person::factory()->create();
    $solution = Solution::factory()->create(); // the only solution there is
    $person->solutions()->attach($solution, ['role' => PersonSolutionRole::Technical->value]);

    $response = $this->actingAs(inlineRelationsAdmin())
        ->get(route('people.show', $person))
        ->assertOk();

    $response->assertSee('href="' . route('solutions.show', $solution) . '" data-ak-inline-edit-link', false);
    $response->assertDontSee('data-ak-inline-edit-field="solution_id"', false);
});

// ── Photo: the profile's image-upload mechanism ──────────────────────────

it('removes the photo through the image-upload "Remover" action', function () {
    Storage::fake('public');

    $person = Person::factory()->create(['photo_path' => 'photos/antiga.png']);

    $this->actingAs(inlineRelationsAdmin())
        ->patchJson(route('people.field.update', $person), ['photo_action' => 'remove'])
        ->assertOk();

    expect($person->fresh()->photo_path)->toBeNull();
});

it('also honours "Remover" from the side panel form, where the button was inert', function () {
    Storage::fake('public');

    $person = Person::factory()->create(['photo_path' => 'photos/antiga.png']);

    $this->actingAs(inlineRelationsAdmin())
        ->patch(route('people.update', $person), [
            'name'         => $person->name,
            'photo_action' => 'remove',
        ], ['Accept' => 'application/json'])
        ->assertOk();

    expect($person->fresh()->photo_path)->toBeNull();
});

it('rejects a photo_action that is not "remove"', function () {
    $person = Person::factory()->create(['photo_path' => 'photos/antiga.png']);

    $this->actingAs(inlineRelationsAdmin())
        ->patchJson(route('people.field.update', $person), ['photo_action' => 'delete-everything'])
        ->assertStatus(422);

    expect($person->fresh()->photo_path)->toBe('photos/antiga.png');
});

it('renders the profile-style upload tile in the header editor, with its own element ids', function () {
    $person = Person::factory()->create();

    $response = $this->actingAs(inlineRelationsAdmin())
        ->get(route('people.show', $person))
        ->assertOk();

    // `avatar-upload.js` binds the tile to its input by id, and the side
    // panel's form renders another `photo` upload — these ids are what keep
    // the two from fighting over the same picker.
    $response->assertSee('id="person-photo-inline-file"', false);
    $response->assertSee('data-ak-avatar-upload', false);
    $response->assertSee('data-ak-inline-edit-field="photo_action"', false);
});

// ── Panel edits must refresh the detail page's other slots ──────────────

it('returns the notes and systems slots when the panel saves, not just the header', function () {
    $person = Person::factory()->create();

    $response = $this->actingAs(inlineRelationsAdmin())
        ->patchJson(route('people.update', $person), ['name' => $person->name, 'notes' => 'via painel'])
        ->assertOk();

    expect(slotIds($response->json()))->toContain('person-notes-slot', 'person-systems-slot', 'person-detail-header-slot');
    expect(slotContent($response->json(), 'person-notes-slot'))->toContain('via painel');
});

it('uploads a photo from the header and keeps the tile mechanism working', function () {
    Storage::fake('public');

    $person = Person::factory()->create(['photo_path' => null]);

    $this->actingAs(inlineRelationsAdmin())
        ->patch(
            route('people.field.update', $person),
            ['photo'  => UploadedFile::fake()->image('nova.png')],
            ['Accept' => 'application/json'],
        )
        ->assertOk();

    Storage::disk('public')->assertExists($person->fresh()->photo_path);
});

it('takes a row\'s unlink ✕ out of the way while that row is being edited', function () {
    // Two identical ✕ side by side — the editor's cancel and the row's
    // unlink — is one misclick away from severing a link instead of
    // abandoning an edit. `x-ui.row-remove` reads the state the editor
    // already publishes (`[data-ak-inline-edit-form]` loses `hidden` when it
    // opens) and goes `invisible`, keeping its slot so the row doesn't reflow.
    $person = Person::factory()->create();
    $solution = Solution::factory()->create();
    $person->solutions()->attach($solution, ['role' => PersonSolutionRole::Technical->value]);
    Contact::factory()->create(['person_id' => $person->id, 'type' => ContactType::Email->value, 'value' => 'extra@x.com']);

    $content = $this->actingAs(inlineRelationsAdmin())
        ->get(route('people.show', $person))
        ->assertOk()
        ->getContent();

    // Both cards on this page hang the rule off `group/row`, so both need the
    // group name the component listens on (the contacts strip used to say
    // `group/contact` and would silently never fire).
    expect(substr_count($content, 'group-has-[[data-ak-inline-edit-form]:not(.hidden)]/row:invisible'))->toBe(2)
        ->and(substr_count($content, 'group/row'))->toBeGreaterThanOrEqual(2);
});
