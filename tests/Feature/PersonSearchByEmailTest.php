<?php

use App\Enums\UserRole;
use App\Models\Contact;
use App\Models\Person;
use App\Models\User;
use Illuminate\Foundation\Testing\LazilyRefreshDatabase;

uses(LazilyRefreshDatabase::class);

/*
|--------------------------------------------------------------------------
| Finding a person by the address you have in hand
|--------------------------------------------------------------------------
|
| The people search covered name, company and solution — and none of the three
| places a person's e-mail actually lives. So typing the one string you are most
| likely to be holding answered "0 registros" for somebody filed under exactly
| that address. Reported from the app 2026-09-02 for admin@leomadeiras.com.br.
|
| One test per place, because each is a different join and losing any one of
| them fails silently: the search still returns rows, just never the right one.
|
*/

it('finds a person by their own e-mail column', function () {
    $target = Person::factory()->create([
        'name'  => 'Nome que ninguém digitaria',
        'email' => 'admin@leomadeiras.com.br',
    ]);
    Person::factory()->create(['name' => 'Outra pessoa', 'email' => 'outra@leomadeiras.com.br']);

    expect(Person::filter(['search' => 'admin@leomadeiras.com.br'])->pluck('id')->all())
        ->toBe([$target->id]);
});

it('finds a person by the e-mail of the account they log in with', function () {
    // The half the report was really about: linking an account is how an
    // address gets attached to a person without their own column ever being
    // filled in, so `people.email` alone can never answer this.
    $user = User::factory()->create([
        'email' => 'admin@leomadeiras.com.br',
        'role'  => UserRole::Admin->value,
    ]);
    $target = Person::factory()->create(['name' => 'Sem e-mail próprio', 'email' => null]);
    $target->user()->associate($user)->save();

    expect($target->fresh()->email)->toBeNull()
        ->and(Person::filter(['search' => 'admin@leomadeiras'])->pluck('id')->all())
        ->toBe([$target->id]);
});

it('finds a person by an additional contact, e-mail or phone', function () {
    $byEmail = Person::factory()->create(['name' => 'Contato secundário', 'email' => null]);
    Contact::factory()->for($byEmail)->create(['value' => 'admin@leomadeiras.com.br']);

    $byPhone = Person::factory()->create(['name' => 'Só telefone', 'email' => null]);
    Contact::factory()->for($byPhone)->create(['value' => '(51) 99876-5432']);

    expect(Person::filter(['search' => 'admin@leomadeiras.com.br'])->pluck('id')->all())->toBe([$byEmail->id])
        ->and(Person::filter(['search' => '99876-5432'])->pluck('id')->all())->toBe([$byPhone->id]);
});

it('matches an e-mail whatever its case, on either driver', function () {
    // Containment AND folding, the rule the rest of the catalog keeps — an
    // address is regularly written back with a capital somewhere.
    $target = Person::factory()->create(['email' => 'Admin@LeoMadeiras.com.BR']);

    expect(Person::filter(['search' => 'admin@leomadeiras.com.br'])->pluck('id')->all())->toBe([$target->id])
        ->and(Person::filter(['search' => 'ADMIN@LEOMADEIRAS'])->pluck('id')->all())->toBe([$target->id]);
});

it('never matches a person through a revoked account', function () {
    // Revoking soft-deletes the account AND unlinks it, so there is nothing
    // left to join through — a person must not stay findable by a credential
    // that was taken away from them.
    $user = User::factory()->create(['email' => 'saiu@leomadeiras.com.br']);
    $person = Person::factory()->create(['name' => 'Quem saiu', 'email' => null]);
    $person->user()->associate($user)->save();

    $person->user()->dissociate()->save();
    $user->delete();

    expect(Person::filter(['search' => 'saiu@leomadeiras.com.br'])->count())->toBe(0);
});

it('still answers the searches it always did', function () {
    // The e-mail branches are added to one `where` group; a missing wrapper
    // would turn every other branch into an unconstrained OR.
    $person = Person::factory()->create(['name' => 'Antônio Gonçalves', 'email' => null]);
    Person::factory()->create(['name' => 'Ninguém relacionado', 'email' => null]);

    expect(Person::filter(['search' => 'antonio goncalves'])->pluck('id')->all())->toBe([$person->id]);
});
