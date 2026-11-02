<?php

use App\Enums\UserRole;
use App\Models\Company;
use App\Models\User;
use App\View\Components\Companies\FilterChips as CompanyChips;
use App\View\Components\Companies\ResultsCount;
use App\View\Components\People\FilterChips as PeopleChips;
use App\View\Components\Solutions\FilterChips as SolutionChips;
use App\View\Components\Submissions\ResultsCount as SubmissionCount;
use Illuminate\Foundation\Testing\LazilyRefreshDatabase;

uses(LazilyRefreshDatabase::class);

/*
| The catalog's filter-chips row and result counter are one shared view each
| (`ui.filter-chips`, `ui.results-count`), driven by a small subclass per
| catalog. These pin what a subclass must still decide: its own slot id, its own
| filter form, its own noun.
*/

it('points each catalog chip row at its own filter form and index', function () {
    $companies = CompanyChips::slot(['search' => 'leo'])['content'];
    $people = PeopleChips::slot(['search' => 'leo'])['content'];
    $solutions = SolutionChips::slot(['search' => 'leo'])['content'];

    expect($companies)->toContain('id="companies-chips-slot"')
        ->toContain('&quot;formId&quot;:&quot;companies-filter-form&quot;')
        ->and($people)->toContain('id="people-chips-slot"')
        ->toContain('&quot;formId&quot;:&quot;people-filter-form&quot;')
        ->and($solutions)->toContain('id="solutions-chips-slot"')
        ->toContain('&quot;formId&quot;:&quot;solutions-filter-form&quot;');
});

it('hides the chip row when no filter is active', function () {
    expect(CompanyChips::slot([])['content'])->toContain('hidden');
});

it('counts with the right noun, on the hero panel or the canvas', function () {
    Company::factory()->create();

    expect(ResultsCount::slot()['content'])
        ->toContain('1</span> empresa')
        ->toContain('glow-ink')
        ->and(SubmissionCount::slot()['content'])
        ->toContain('0 submissões')
        ->toContain('text-muted');
});

it('survives an unknown enum value in the query string instead of a 500', function () {
    $admin = User::factory()->create(['role' => UserRole::Admin->value]);

    $this->actingAs($admin)->get(route('people.index', ['filter' => ['role' => 'nao-existe']]))->assertOk();
    $this->actingAs($admin)->get(route('companies.index', ['filter' => ['kind' => 'nao-existe']]))->assertOk();
});
