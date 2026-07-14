<?php

use App\Enums\UserRole;
use App\Models\Contact;
use App\Models\Person;
use App\Models\User;
use Illuminate\Foundation\Testing\LazilyRefreshDatabase;

uses(LazilyRefreshDatabase::class);

function contactsAdmin(): User
{
    return User::factory()->create(['role' => UserRole::Admin->value]);
}

it('creates additional contacts alongside the single email/phone fields', function () {
    $this->actingAs(contactsAdmin())
        ->postJson(route('people.store'), [
            'name'     => 'Contato AccessOne',
            'email'    => 'principal@accessone.com.br',
            'contacts' => [
                ['type' => 'email', 'value' => 'leonardo.gardini@accessone.com.br'],
                ['type' => 'email', 'value' => 'eudes.sousa@accessone.com.br'],
            ],
        ])
        ->assertOk()
        ->assertJson(['type' => 'success']);

    $person = Person::where('name', 'Contato AccessOne')->firstOrFail();
    expect($person->email)->toBe('principal@accessone.com.br')
        ->and($person->contacts)->toHaveCount(2)
        ->and($person->contacts->pluck('value')->all())->toBe([
            'leonardo.gardini@accessone.com.br',
            'eudes.sousa@accessone.com.br',
        ]);
});

it('updates an existing contact by id, adds a new one, and deletes a removed one', function () {
    $person = Person::factory()->create();
    $keep = Contact::factory()->for($person)->create(['type' => 'email', 'value' => 'old@x.com']);
    $remove = Contact::factory()->for($person)->create(['type' => 'phone', 'value' => '11999990000']);

    $this->actingAs(contactsAdmin())
        ->patchJson(route('people.update', $person), [
            'name'     => $person->name,
            'contacts' => [
                ['id' => $keep->id, 'type' => 'email', 'value' => 'new@x.com'],
                ['type' => 'whatsapp', 'value' => '11988887777'],
                // $remove is intentionally missing from this payload — the user deleted that row in the form.
            ],
        ])
        ->assertOk();

    $person->refresh();
    expect($person->contacts)->toHaveCount(2);

    $updated = $person->contacts->firstWhere('id', $keep->id);
    expect($updated->value)->toBe('new@x.com');

    $this->assertModelMissing($remove);
    expect($person->contacts->firstWhere('type', 'whatsapp')?->value)->toBe('11988887777');
});

it('ignores a blank contact row (added in the form but never filled in)', function () {
    $person = Person::factory()->create();

    $this->actingAs(contactsAdmin())
        ->patchJson(route('people.update', $person), [
            'name'     => $person->name,
            'contacts' => [
                ['type' => 'email', 'value' => ''],
            ],
        ])
        ->assertOk();

    expect($person->fresh()->contacts)->toBeEmpty();
});

it('deletes every contact when the form submits an empty contacts array', function () {
    $person = Person::factory()->create();
    Contact::factory()->for($person)->create();

    $this->actingAs(contactsAdmin())
        ->patchJson(route('people.update', $person), [
            'name'     => $person->name,
            'contacts' => [],
        ])
        ->assertOk();

    expect($person->fresh()->contacts)->toBeEmpty();
});

it('leaves contacts untouched when the request omits the contacts key entirely', function () {
    $person = Person::factory()->create();
    Contact::factory()->for($person)->create();

    $this->actingAs(contactsAdmin())
        ->patchJson(route('people.update', $person), ['name' => 'Novo nome'])
        ->assertOk();

    expect($person->fresh()->contacts)->toHaveCount(1);
});

it('rejects a contact id that belongs to a different person', function () {
    $person = Person::factory()->create();
    $other = Person::factory()->create();
    $foreignContact = Contact::factory()->for($other)->create();

    $this->actingAs(contactsAdmin())
        ->patchJson(route('people.update', $person), [
            'name'     => $person->name,
            'contacts' => [
                ['id' => $foreignContact->id, 'type' => 'email', 'value' => 'hijack@x.com'],
            ],
        ])
        ->assertStatus(422);

    expect($foreignContact->fresh()->value)->not->toBe('hijack@x.com');
});

it('includes contacts in the edit panel payload so the form can preload them', function () {
    $person = Person::factory()->create();
    Contact::factory()->for($person)->create(['type' => 'email', 'value' => 'a@b.com']);

    $response = $this->actingAs(contactsAdmin())
        ->getJson(route('people.edit', $person))
        ->assertOk()
        ->json();

    expect($response['content'])->toContain('a@b.com');
});
