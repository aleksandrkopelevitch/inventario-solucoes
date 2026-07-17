<?php

use App\Models\DocumentationPage;
use App\Models\Integration;
use App\Models\Solution;
use App\Models\User;
use App\Services\DocumentationCoverageService;
use Illuminate\Foundation\Testing\LazilyRefreshDatabase;

uses(LazilyRefreshDatabase::class);

/** Creates an integration linked to a solution (pivot), without creating extra solutions. */
function integrationFor(Solution $solution, ?string $documentation, string $name): Integration
{
    $integration = Integration::factory()->create([
        'name'               => $name,
        'source_solution_id' => $solution->id,
        'target_solution_id' => $solution->id,
        'documentation'      => $documentation,
    ]);

    attachParticipants($integration, [[$solution, 0]]);

    return $integration;
}

/** Creates a Solution already with a documentation page with the given content (or none, if null). */
function solutionWithDoc(?string $documentation, array $attributes = []): Solution
{
    $solution = Solution::factory()->create($attributes);

    if ($documentation !== null) {
        DocumentationPage::factory()->for($solution, 'container')->create(['documentation' => $documentation]);
    }

    return $solution;
}

it('computes coverage counters from real content, for solutions and integrations', function () {
    $a = solutionWithDoc('# Doc');
    $b = solutionWithDoc('# Doc');
    solutionWithDoc(null);

    integrationFor($a, '# Doc', 'Int documentada');
    integrationFor($b, '', 'Int vazia');       // empty string = pending
    integrationFor($b, null, 'Int nula');      // null = pending

    $counters = (new DocumentationCoverageService)->counters();

    expect($counters['solutions'])->toBe(['documented' => 2, 'total' => 3, 'percent' => round(2 / 3 * 100)])
        ->and($counters['integrations'])->toBe(['documented' => 1, 'total' => 3, 'percent' => round(1 / 3 * 100)]);
});

it('filters the list by pending status', function () {
    solutionWithDoc('# Doc', ['name' => 'Documentada']);
    solutionWithDoc(null, ['name' => 'Pendente']);

    $groups = (new DocumentationCoverageService)->groups(['status' => 'pending']);

    expect($groups->pluck('solution.name')->all())->toBe(['Pendente']);
});

it('filters the list by item type', function () {
    $solution = solutionWithDoc('# Doc', ['name' => 'Solução X']);
    integrationFor($solution, null, 'Integração Y');

    $service = new DocumentationCoverageService;

    // Solutions only: the group appears, with no integration rows.
    $onlySolutions = $service->groups(['type' => 'solutions']);
    expect($onlySolutions)->toHaveCount(1)
        ->and($onlySolutions->first()['solution']['showStatus'])->toBeTrue()
        ->and($onlySolutions->first()['integrations'])->toBeEmpty();

    // Integrations only: the group appears via the integration; the solution status isn't shown.
    $onlyIntegrations = $service->groups(['type' => 'integrations']);
    expect($onlyIntegrations)->toHaveCount(1)
        ->and($onlyIntegrations->first()['solution']['showStatus'])->toBeFalse()
        ->and($onlyIntegrations->first()['integrations']->pluck('name')->all())->toBe(['Integração Y']);
});

it('searches by solution name or integration name', function () {
    Solution::factory()->create(['name' => 'Alpha']);
    $beta = Solution::factory()->create(['name' => 'Beta']);
    integrationFor($beta, null, 'Gamma');

    $groups = (new DocumentationCoverageService)->groups(['search' => 'gamma']);

    expect($groups->pluck('solution.name')->all())->toBe(['Beta']);
});

it('renders the documentation hub for any authenticated user', function () {
    Solution::factory()->create(['name' => 'Minha Solução']);

    $response = $this->actingAs(User::factory()->create()) // viewer
        ->get(route('documentation.index'))
        ->assertOk()
        ->assertSee('Documentação')
        ->assertSee('Minha Solução')
        ->assertSee('Sem documentação')
        // The filter/search hooks need to compile (the @json-in-component-attribute
        // bug fails silently — see CLAUDE.md); confirms the encoded JSON is
        // present and no leftover uncompiled directive.
        ->assertSee('data-ak-filters', false)
        ->assertSee('data-ak-search', false);

    expect($response->getContent())->not->toContain('@json(');
});

it('returns the hub slot as JSON when filtering', function () {
    Solution::factory()->create(['name' => 'Filtrada']);

    $response = $this->actingAs(User::factory()->create())
        ->getJson(route('documentation.index', ['filter' => ['status' => 'pending']]))
        ->assertOk();

    expect($response->json('updatableSlots.0.id'))->toBe('documentation-hub-slot')
        ->and($response->json('updatableSlots.0.content'))->toContain('Filtrada');
});

it('redirects the retired coverage URL to the documentation hub', function () {
    $this->actingAs(User::factory()->create())
        ->get('/solutions/coverage')
        ->assertRedirect('/documentation');
});
