<?php

use App\Models\DocumentationPage;
use App\Models\Integration;
use App\Models\Solution;
use App\Models\User;
use App\Services\DocumentationCoverageService;
use Illuminate\Foundation\Testing\LazilyRefreshDatabase;

uses(LazilyRefreshDatabase::class);

/** Cria uma integração ligada a uma solução (pivot), sem criar soluções extra. */
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

/** Cria uma Solution já com uma página de documentação com o conteúdo dado (ou nenhuma, se null). */
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
    integrationFor($b, '', 'Int vazia');       // string vazia = pendente
    integrationFor($b, null, 'Int nula');      // null = pendente

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

    // Só soluções: o grupo aparece, sem linhas de integração.
    $onlySolutions = $service->groups(['type' => 'solutions']);
    expect($onlySolutions)->toHaveCount(1)
        ->and($onlySolutions->first()['solution']['showStatus'])->toBeTrue()
        ->and($onlySolutions->first()['integrations'])->toBeEmpty();

    // Só integrações: o grupo aparece pela integração; o status da solução não é mostrado.
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
        // Os hooks de filtro/busca precisam compilar (o bug do @json em atributo
        // de componente falha em silêncio — ver CLAUDE.md); confirma o JSON
        // encodado presente e nenhum resquício de diretiva não compilada.
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
        ->get('/solucoes/cobertura')
        ->assertRedirect('/documentacao');
});
