<?php

use App\Enums\UserRole;
use App\Models\Company;
use App\Models\Notebook;
use App\Models\User;
use Illuminate\Foundation\Testing\LazilyRefreshDatabase;

uses(LazilyRefreshDatabase::class);

/*
| A slug that equals a static route segment makes its record unreachable: the
| static route is registered first and answers instead. Each family refuses its
| own segments both when a slug is GENERATED and when one is POSTED.
*/

function slugAdmin(): User
{
    return User::factory()->create(['role' => UserRole::Admin->value]);
}

it('never generates a caderno slug a route owns, whoever creates the caderno', function () {
    expect(Notebook::uniqueSlug('Search'))->toBe('search-2')
        ->and(Notebook::uniqueSlug('Panel'))->toBe('panel-2')
        ->and(Notebook::uniqueSlug('Operação'))->toBe('operacao');
});

it('never generates a company slug a route owns', function () {
    $this->actingAs(slugAdmin())
        ->postJson(route('companies.store'), ['name' => 'New', 'kind' => 'vendor'])
        ->assertOk();

    expect(Company::where('name', 'New')->value('slug'))->toBe('new-2');
});

it('refuses a posted person or company slug a route owns', function () {
    $this->actingAs(slugAdmin())
        ->postJson(route('people.store'), ['name' => 'Fulano', 'slug' => 'accounts'])
        ->assertStatus(422);

    $this->actingAs(slugAdmin())
        ->postJson(route('companies.store'), ['name' => 'Acme', 'kind' => 'vendor', 'slug' => 'new'])
        ->assertStatus(422);
});
