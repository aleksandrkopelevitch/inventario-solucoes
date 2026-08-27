<?php

use App\Models\DocumentationPage;
use App\Models\Notebook;
use App\Models\Solution;
use App\Models\User;
use App\Services\DocumentationCoverageService;
use Illuminate\Foundation\Testing\LazilyRefreshDatabase;

uses(LazilyRefreshDatabase::class);

/**
 * A caderno with one page of the given content (or no page at all, if null),
 * linked to a solution — the shape coverage is measured on now: a solution is
 * documented THROUGH a notebook, never by owning pages of its own.
 */
function notebookWithDoc(?string $documentation, array $attributes = []): Notebook
{
    $notebook = Notebook::factory()->create($attributes);
    $notebook->solutions()->attach(Solution::factory()->create(['name' => $attributes['name'] ?? fake()->unique()->company()]));

    if ($documentation !== null) {
        DocumentationPage::factory()->for($notebook)->create(['documentation' => $documentation]);
    }

    return $notebook;
}

it('computes coverage counters from real content, for solutions, cadernos and pages', function () {
    notebookWithDoc('# Doc');
    $b = notebookWithDoc('# Doc');
    notebookWithDoc(null);

    // Two more pages in B, one of them empty — the page counter is about pages,
    // not cadernos, which is what separates the numbers.
    DocumentationPage::factory()->for($b)->create(['documentation' => '# Outra']);
    DocumentationPage::factory()->for($b)->create(['documentation' => '']);

    $counters = (new DocumentationCoverageService)->counters();

    expect($counters['solutions'])->toBe(['documented' => 2, 'total' => 3, 'percent' => round(2 / 3 * 100)])
        ->and($counters['notebooks'])->toBe(['documented' => 2, 'total' => 3, 'percent' => round(2 / 3 * 100)])
        ->and($counters['pages'])->toBe(['documented' => 3, 'total' => 4, 'percent' => round(3 / 4 * 100)]);
});

it('counts a solution as documented through ANY caderno linked to it', function () {
    // The whole reason the container moved: one caderno covers every system it
    // describes, and a solution needs only one of its cadernos to have content.
    $solution = Solution::factory()->create();
    $empty = Notebook::factory()->create();
    $written = Notebook::factory()->create();
    DocumentationPage::factory()->for($written)->create(['documentation' => '# Doc']);
    $solution->notebooks()->attach([$empty->id, $written->id]);

    expect((new DocumentationCoverageService)->counters()['solutions']['documented'])->toBe(1);

    // …and the same caderno covers a second solution at no extra cost.
    $second = Solution::factory()->create();
    $second->notebooks()->attach($written);

    expect((new DocumentationCoverageService)->counters()['solutions']['documented'])->toBe(2);
});

it('filters the list by pending status', function () {
    notebookWithDoc('# Doc', ['name' => 'Documentado']);
    notebookWithDoc(null, ['name' => 'Pendente']);

    $groups = (new DocumentationCoverageService)->groups(['status' => 'pending']);

    expect($groups->pluck('notebook.name')->all())->toBe(['Pendente']);
});

it('keeps a documented caderno visible for the sake of its own empty page', function () {
    $notebook = notebookWithDoc('# Doc', ['name' => 'Meio documentado']);
    DocumentationPage::factory()->for($notebook)->create(['title' => 'Vazia', 'documentation' => '']);

    $groups = (new DocumentationCoverageService)->groups(['status' => 'pending']);

    // The caderno itself passes the "documented" bar, so the pending filter
    // rejects it — but one of its pages doesn't, and that page is the whole
    // reason someone filtered.
    expect($groups->pluck('notebook.name')->all())->toBe(['Meio documentado'])
        ->and($groups->first()['pages']->pluck('title')->all())->toBe(['Vazia']);
});

it('lists a caderno\'s pages alphabetically', function () {
    $notebook = notebookWithDoc(null, ['name' => 'Caderno X']);
    DocumentationPage::factory()->for($notebook)->create(['title' => 'Zeta', 'documentation' => '# z']);
    DocumentationPage::factory()->for($notebook)->create(['title' => 'Alfa', 'documentation' => '# a']);

    $pages = (new DocumentationCoverageService)->groups([])
        ->firstWhere('notebook.name', 'Caderno X')['pages'];

    // Alphabetical, deliberately: `position` orders siblings only, so a flat
    // ordering by it across depths is neither the tree nor anything else.
    expect($pages->pluck('title')->all())->toBe(['Alfa', 'Zeta']);
});

it('names the solutions a caderno documents on its own row', function () {
    $notebook = Notebook::factory()->create(['name' => 'Integrações']);
    $notebook->solutions()->attach(Solution::factory()->create(['name' => 'SAP']));
    $notebook->solutions()->attach(Solution::factory()->create(['name' => 'AllStrategy']));

    $row = (new DocumentationCoverageService)->groups([])->firstWhere('notebook.name', 'Integrações');

    expect(collect($row['notebook']['solutions'])->pluck('name')->all())->toBe(['AllStrategy', 'SAP']);
});

it('searches by caderno name, page title or the name of a solution it documents', function () {
    Notebook::factory()->create(['name' => 'Alpha']);
    $beta = Notebook::factory()->create(['name' => 'Beta']);
    DocumentationPage::factory()->for($beta)->create(['title' => 'Gamma', 'documentation' => '# g']);

    $service = new DocumentationCoverageService;

    expect($service->groups(['search' => 'gamma'])->pluck('notebook.name')->all())->toBe(['Beta']);

    // A caderno is findable by the system it describes, which is what makes
    // "where are the SAP docs?" answerable without knowing the caderno's name.
    $beta->solutions()->attach(Solution::factory()->create(['name' => 'SAP ECC']));

    expect($service->groups(['search' => 'SAP EC'])->pluck('notebook.name')->all())->toBe(['Beta']);
});

it('lists the solutions no caderno covers yet, and whether one is at least linked', function () {
    // The gap the hub exists to show, and the half a per-caderno listing
    // structurally cannot: an undocumented solution has no caderno to appear
    // under.
    Solution::factory()->create(['name' => 'Nunca tocada']);

    $linkedButEmpty = Solution::factory()->create(['name' => 'Começada']);
    $linkedButEmpty->notebooks()->attach(Notebook::factory()->create());

    notebookWithDoc('# Doc', ['name' => 'Pronta']);

    $gaps = (new DocumentationCoverageService)->undocumentedSolutions();

    expect($gaps->pluck('name')->all())->toBe(['Começada', 'Nunca tocada'])
        // Zero means nothing linked at all; non-zero means a caderno is linked
        // but still empty — two different jobs to do.
        ->and($gaps->pluck('notebookCount')->all())->toBe([1, 0]);
});

it('renders the documentation hub for any authenticated user', function () {
    Solution::factory()->create(['name' => 'Minha Solução']);
    Notebook::factory()->create(['name' => 'Meu Caderno']);

    $response = $this->actingAs(User::factory()->create()) // viewer
        ->get(route('documentation.index'))
        ->assertOk()
        ->assertSee('Documentação')
        ->assertSee('Meu Caderno')
        ->assertSee('Minha Solução')
        ->assertSee('Sem conteúdo')
        // The filter/search hooks need to compile (the @json-in-component-attribute
        // bug fails silently — see AGENTS.md); confirms the encoded JSON is
        // present and no leftover uncompiled directive.
        ->assertSee('data-ak-filters', false)
        ->assertSee('data-ak-search', false);

    expect($response->getContent())->not->toContain('@json(');
});

it('returns the hub slot as JSON when filtering', function () {
    Notebook::factory()->create(['name' => 'Filtrado']);

    $response = $this->actingAs(User::factory()->create())
        ->getJson(route('documentation.index', ['filter' => ['status' => 'pending']]))
        ->assertOk();

    expect($response->json('updatableSlots.0.id'))->toBe('documentation-hub-slot')
        ->and($response->json('updatableSlots.0.content'))->toContain('Filtrado');
});

it('redirects the retired coverage URL to the documentation hub', function () {
    $this->actingAs(User::factory()->create())
        ->get('/solutions/coverage')
        ->assertRedirect('/documentation');
});
