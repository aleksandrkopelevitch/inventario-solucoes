<?php

use App\Enums\UserRole;
use App\Models\Company;
use App\Models\Person;
use App\Models\Solution;
use App\Models\User;
use Database\Seeders\AttributeOptionSeeder;
use Database\Seeders\IntegrationSeeder;
use Database\Seeders\SolutionSeeder;
use Illuminate\Foundation\Testing\LazilyRefreshDatabase;

uses(LazilyRefreshDatabase::class);

/**
 * Crawl de todas as páginas com os dados REAIS do seed (não factories
 * isoladas) — pega classes de bug que só aparecem com o dataset completo,
 * como registros com campos opcionais em branco. Achou um bug real na
 * revisão de 2026-07-02: `SolutionSeeder` criava uma Company vazia
 * (name/slug='') para soluções sem fornecedor no inventário, e
 * `route('companies.show', $company)` quebrava com 500 ao tentar gerar a
 * URL com um slug vazio.
 */
it('renders every detail page for every seeded record without a 500', function () {
    $this->seed(AttributeOptionSeeder::class);
    $this->seed(SolutionSeeder::class);
    $this->seed(IntegrationSeeder::class);

    $admin = User::factory()->create(['role' => UserRole::Admin->value]);
    $this->actingAs($admin);

    foreach (Solution::all() as $solution) {
        $this->get(route('solutions.show', $solution))->assertStatus(200);
        $this->get(route('solutions.edit', $solution))->assertStatus(200);
    }

    foreach (Person::all() as $person) {
        $this->get(route('people.show', $person))->assertStatus(200);
        $this->get(route('people.edit', $person))->assertStatus(200);
    }

    foreach (Company::all() as $company) {
        $this->get(route('companies.show', $company))->assertStatus(200);
        $this->get(route('companies.edit', $company))->assertStatus(200);
    }
});

it('renders every index/catalog page for both admin and viewer without a 500', function () {
    $this->seed(AttributeOptionSeeder::class);
    $this->seed(SolutionSeeder::class);
    $this->seed(IntegrationSeeder::class);

    $routes = [
        route('profile.show'),
        route('showcase'),
        route('solutions.index'),
        route('people.index'),
        route('companies.index'),
        route('documentation.index'),
        route('solutions.map'),
        route('solutions.map.data'),
    ];

    foreach ([User::factory()->create(['role' => UserRole::Admin->value]), User::factory()->create()] as $user) {
        $this->actingAs($user);

        foreach ($routes as $url) {
            $this->get($url)->assertStatus(200);
        }
    }
});
