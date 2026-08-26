<?php

use App\Enums\UserRole;
use App\Models\Company;
use App\Models\Person;
use App\Models\Solution;
use App\Models\User;
use Database\Seeders\AttributeOptionSeeder;
use Database\Seeders\DiagramSeeder;
use Database\Seeders\SolutionSeeder;
use Illuminate\Foundation\Testing\LazilyRefreshDatabase;

uses(LazilyRefreshDatabase::class);

/**
 * Crawls every page with the REAL seed data (not isolated factories) —
 * catches classes of bugs that only show up with the full dataset, such
 * as records with blank optional fields. Found a real bug in the
 * 2026-07-02 review: `SolutionSeeder` created an empty Company
 * (name/slug='') for solutions without a vendor in the inventory, and
 * `route('companies.show', $company)` broke with a 500 when trying to
 * generate the URL with an empty slug.
 */
it('renders every detail page for every seeded record without a 500', function () {
    $this->seed(AttributeOptionSeeder::class);
    $this->seed(SolutionSeeder::class);
    $this->seed(DiagramSeeder::class);

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
    $this->seed(DiagramSeeder::class);

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
