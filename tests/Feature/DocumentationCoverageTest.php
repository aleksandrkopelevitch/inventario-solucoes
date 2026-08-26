<?php

use App\Models\DocumentationPage;
use App\Models\Diagram;
use App\Models\Solution;
use App\Models\User;
use App\Services\DocumentationCoverageService;
use Illuminate\Foundation\Testing\LazilyRefreshDatabase;

uses(LazilyRefreshDatabase::class);

/** Creates a Solution already with a documentation page with the given content (or none, if null). */
function solutionWithDoc(?string $documentation, array $attributes = []): Solution
{
    $solution = Solution::factory()->create($attributes);

    if ($documentation !== null) {
        DocumentationPage::factory()->for($solution, 'container')->create(['documentation' => $documentation]);
    }

    return $solution;
}

it('computes coverage counters from real content, for solutions and their pages', function () {
    solutionWithDoc('# Doc');
    $b = solutionWithDoc('# Doc');
    solutionWithDoc(null);

    // Two more pages on B, one of them empty — the second counter is about
    // pages, not solutions, so this is what separates the two numbers.
    DocumentationPage::factory()->for($b, 'container')->create(['documentation' => '# Outra']);
    DocumentationPage::factory()->for($b, 'container')->create(['documentation' => '']);

    $counters = (new DocumentationCoverageService)->counters();

    expect($counters['solutions'])->toBe(['documented' => 2, 'total' => 3, 'percent' => round(2 / 3 * 100)])
        ->and($counters['pages'])->toBe(['documented' => 3, 'total' => 4, 'percent' => round(3 / 4 * 100)]);
});

it('filters the list by pending status', function () {
    solutionWithDoc('# Doc', ['name' => 'Documentada']);
    solutionWithDoc(null, ['name' => 'Pendente']);

    $groups = (new DocumentationCoverageService)->groups(['status' => 'pending']);

    expect($groups->pluck('solution.name')->all())->toBe(['Pendente']);
});

it('keeps a documented solution visible for the sake of its own empty page', function () {
    $solution = solutionWithDoc('# Doc', ['name' => 'Meia documentada']);
    DocumentationPage::factory()->for($solution, 'container')->create(['title' => 'Vazia', 'documentation' => '']);

    $groups = (new DocumentationCoverageService)->groups(['status' => 'pending']);

    // The solution itself passes the "documented" bar, so the pending filter
    // rejects it — but one of its pages doesn't, and that page is the whole
    // reason someone filtered.
    expect($groups->pluck('solution.name')->all())->toBe(['Meia documentada'])
        ->and($groups->first()['pages']->pluck('title')->all())->toBe(['Vazia']);
});

it('lists a solution\'s pages alphabetically, marking the ones with a diagram', function () {
    $solution = solutionWithDoc(null, ['name' => 'Solução X']);
    DocumentationPage::factory()->for($solution, 'container')->create(['title' => 'Zeta', 'documentation' => '# z']);
    $alfa = DocumentationPage::factory()->for($solution, 'container')->create(['title' => 'Alfa', 'documentation' => '# a']);
    $alfa->diagram()->associate(Diagram::factory()->create())->save();

    // By name, not `first()`: `Diagram::factory()` creates its own
    // source/target solutions, so this solution is not alone in the list.
    $pages = (new DocumentationCoverageService)->groups([])
        ->firstWhere('solution.name', 'Solução X')['pages'];

    // Alphabetical, deliberately: `position` orders siblings only, so a flat
    // ordering by it across depths is neither the tree nor anything else.
    expect($pages->pluck('title')->all())->toBe(['Alfa', 'Zeta'])
        ->and($pages->pluck('hasDiagram')->all())->toBe([true, false]);
});

it('searches by solution name or page title', function () {
    Solution::factory()->create(['name' => 'Alpha']);
    $beta = Solution::factory()->create(['name' => 'Beta']);
    DocumentationPage::factory()->for($beta, 'container')->create(['title' => 'Gamma', 'documentation' => '# g']);

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
        // bug fails silently — see AGENTS.md); confirms the encoded JSON is
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
