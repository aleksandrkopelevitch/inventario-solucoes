<?php

use App\Enums\PersonSolutionRole;
use App\Models\Company;
use App\Models\Person;
use App\Models\Solution;
use Database\Seeders\SolutionSeeder;
use Illuminate\Foundation\Testing\LazilyRefreshDatabase;
use Illuminate\Support\Facades\DB;

uses(LazilyRefreshDatabase::class);

it('imports the 81 solutions from the inventory JSON', function () {
    $this->seed(SolutionSeeder::class);

    expect(Solution::count())->toBe(81)
        ->and(Solution::where('slug', 'digibee-ipaas')->exists())->toBeTrue();
});

it('is idempotent — running twice does not duplicate', function () {
    $this->seed(SolutionSeeder::class);

    $solutions = Solution::count();
    $companies = Company::count();
    $people = Person::count();
    $links = DB::table('person_solution')->count();

    $this->seed(SolutionSeeder::class);

    expect(Solution::count())->toBe($solutions)
        ->and(Solution::count())->toBe(81)
        ->and(Company::count())->toBe($companies)
        ->and(Person::count())->toBe($people)
        ->and(DB::table('person_solution')->count())->toBe($links);
});

it('splits an owner_tech "A / B" into two technical links', function () {
    $records = json_decode(file_get_contents(database_path('data/inventory_seed.json')), true);

    $split = collect($records)->first(fn ($r) => str_contains((string) ($r['owner_tech'] ?? ''), '/'));
    expect($split)->not->toBeNull();

    $this->seed(SolutionSeeder::class);

    $names = collect(preg_split('/[\/;]/', $split['owner_tech']))
        ->map(fn ($n) => trim($n))
        ->filter()
        ->unique();

    $solution = Solution::where('slug', $split['slug'])->firstOrFail();

    expect($solution->people()->wherePivot('role', PersonSolutionRole::Technical->value)->count())
        ->toBe($names->count())
        ->toBeGreaterThanOrEqual(2);
});

it('derives internal support_type for Leo-owned solutions', function () {
    $this->seed(SolutionSeeder::class);

    $svl = Solution::where('slug', 'svl-sistema-de-vendas-leo')->firstOrFail();

    expect($svl->support_type)->toBe('internal')
        ->and($svl->vendor->kind->value)->toBe('internal');
});
