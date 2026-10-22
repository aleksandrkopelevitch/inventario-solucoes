<?php

use App\Enums\UserRole;
use App\Http\Controllers\KnowledgeBaseController;
use App\Models\DocumentationPage;
use App\Models\Notebook;
use App\Models\User;
use App\Services\DocumentationPageService;
use App\Services\DocumentationSearchService;
use Illuminate\Foundation\Testing\LazilyRefreshDatabase;

uses(LazilyRefreshDatabase::class);

/*
|--------------------------------------------------------------------------
| The knowledge base's palette — every published caderno, or one of them
|--------------------------------------------------------------------------
|
| `GET docs/search`: without `notebook` it spans every caderno the rail lists
| (DocumentationSearchService::searchAll()), with a slug it is that caderno's
| own search. Helpers are this file's own: a parallel run splits files, and a
| Pest function defined in another one is not there.
|
*/

function kbsNotebook(string $name, bool $published = true): Notebook
{
    $factory = Notebook::factory();

    return ($published ? $factory->published() : $factory)->create(['name' => $name, 'slug' => Str::slug($name)]);
}

function kbsPage(Notebook $notebook, string $title, string $body): DocumentationPage
{
    $page = new DocumentationPage(['title' => $title, 'slug' => Str::slug($title), 'documentation' => $body, 'position' => 0]);
    $page->notebook()->associate($notebook);
    $page->save();

    return $page;
}

/** The palette's one updatable slot, for a search as an ordinary reader. */
function kbsSearch(array $query): string
{
    return (string) test()->actingAs(User::factory()->create())
        ->getJson(route('docs.search', $query))
        ->assertOk()
        ->json('updatableSlots.0.content');
}

it('searches every published caderno at once, and links each hit into its own caderno', function () {
    $sap = kbsNotebook('Integrações SAP');
    $sapPage = kbsPage($sap, 'Autenticação SAP', 'O header usa um token bearer.');
    $digibee = kbsNotebook('Manual do Digibee');
    $digibeePage = kbsPage($digibee, 'Credenciais', "# Credenciais\n\nGere um token bearer no portal.\n\n## Validade\n\nO token bearer expira.");
    $draft = kbsNotebook('Rascunho', published: false);
    kbsPage($draft, 'Segredo', 'Um token bearer que ninguém publicou.');

    $html = kbsSearch(['q' => 'bearer']);

    expect($html)
        ->toContain('Autenticação SAP')
        ->toContain('Credenciais')
        // Each hit opens in its own caderno, at the section it matched.
        ->toContain(route('docs.page', [$sap, $sapPage]))
        ->toContain(route('docs.page', [$digibee, $digibeePage]) . '#validade')
        // …and says which caderno it is, ahead of the breadcrumb.
        ->toContain('Integrações SAP')
        ->toContain('Manual do Digibee')
        // Never a caderno nobody published.
        ->not->toContain('Rascunho')
        ->not->toContain('ninguém publicou');
});

it('leaves the home caderno out — its text is the landing, not documentation', function () {
    $home = kbsNotebook('Base de conhecimento');
    $home->forceFill(['is_home' => true])->save();
    kbsPage($home, 'Boas-vindas', 'Aqui você encontra a palavra zebra.');

    expect(kbsSearch(['q' => 'zebra']))->not->toContain('Boas-vindas');

    $this->actingAs(User::factory()->create())
        ->getJson(route('docs.search', ['notebook' => $home->slug, 'q' => 'zebra']))
        ->assertNotFound();
});

it('narrows to one caderno when pointed at it', function () {
    $sap = kbsNotebook('Integrações SAP');
    kbsPage($sap, 'Autenticação SAP', 'Use um token bearer.');
    $digibee = kbsNotebook('Manual do Digibee');
    kbsPage($digibee, 'Credenciais Digibee', 'Use um token bearer.');

    expect(kbsSearch(['notebook' => $sap->slug, 'q' => 'bearer']))
        ->toContain('Autenticação SAP')
        ->not->toContain('Credenciais Digibee');
});

it('404s a slug that names no published caderno, instead of answering empty', function () {
    $draft = kbsNotebook('Rascunho', published: false);
    $reader = User::factory()->create();

    foreach (['nao-existe', $draft->slug] as $slug) {
        $this->actingAs($reader)
            ->getJson(route('docs.search', ['notebook' => $slug, 'q' => 'x']))
            ->assertNotFound();
    }
});

it('lets a query name the caderno it means', function () {
    // The caderno's name is part of every entry's breadcrumb, so the second
    // word picks WHICH of two identical sections.
    kbsPage(kbsNotebook('Manual do Digibee'), 'Limites', "## Timeout\n\nTrinta segundos.");
    kbsPage(kbsNotebook('Integrações SAP'), 'Limites RFC', "## Timeout\n\nSessenta segundos.");

    $html = kbsSearch(['q' => 'digibee timeout']);

    expect($html)->toContain('Trinta segundos')->not->toContain('Sessenta segundos');
});

it('chips the cadernos with their hit counts, and keeps a caderno with none in the row', function () {
    kbsPage(kbsNotebook('Integrações SAP'), 'Pedido', 'pedido de venda');
    kbsPage(kbsNotebook('Manual do Digibee'), 'Pipeline', 'nada a ver');

    $html = kbsSearch(['q' => 'pedido']);

    expect($html)
        ->toContain('data-ak-docs-search-facet="notebook"')
        ->toContain('data-ak-docs-search-value="integracoes-sap"')
        ->toContain('data-ak-docs-search-value="manual-do-digibee"')
        ->toContain('opacity-45'); // the Digibee chip, dimmed at zero
});

it('honours the scopes across cadernos', function () {
    kbsPage(kbsNotebook('Integrações SAP'), 'Campos', "Texto corrido.\n\n| Campo | Tipo |\n| --- | --- |\n| VBELN | char |");
    kbsPage(kbsNotebook('Manual do Digibee'), 'Mapeamento', 'O campo VBELN vem do SAP.');

    $html = kbsSearch(['q' => 'vbeln', 'filter' => ['scopes' => ['table']]]);

    expect($html)->toContain('Campos')->not->toContain('Mapeamento');
});

it('indexes cold cadernos a few per request and says which are still missing', function () {
    // A budget spent at once: the first caderno is always built, every other
    // cold one waits for the next request.
    app()->instance(DocumentationSearchService::class, new class(app(DocumentationPageService::class)) extends DocumentationSearchService
    {
        protected function globalBuildBudget(): float
        {
            return 0.0;
        }
    });

    kbsPage(kbsNotebook('A Primeiro'), 'Um', 'termo comum');
    kbsPage(kbsNotebook('B Segundo'), 'Dois', 'termo comum');
    kbsPage(kbsNotebook('C Terceiro'), 'Três', 'termo comum');

    $search = app(DocumentationSearchService::class);
    $notebooks = Notebook::query()->published()->orderBy('name')->get();

    expect($search->searchAll($notebooks, 'termo')['pending'])->toBe(['B Segundo', 'C Terceiro'])
        ->and($search->searchAll($notebooks, 'termo')['pending'])->toBe(['C Terceiro']);

    $complete = $search->searchAll($notebooks, 'termo');

    expect($complete['pending'])->toBe([])
        ->and($complete['total'])->toBe(3);
});

it('tells the palette when the answer is partial, so it asks again', function () {
    app()->instance(DocumentationSearchService::class, new class(app(DocumentationPageService::class)) extends DocumentationSearchService
    {
        protected function globalBuildBudget(): float
        {
            return 0.0;
        }
    });

    kbsPage(kbsNotebook('A Primeiro'), 'Um', 'termo');
    kbsPage(kbsNotebook('B Segundo'), 'Dois', 'termo');

    expect(kbsSearch(['q' => 'termo']))
        ->toContain('data-ak-docs-search-indexing')
        ->toContain('Indexando 1 caderno')
        ->toContain('B Segundo');
});

it('sees an edit at once — the global index is keyed by content', function () {
    $sap = kbsNotebook('Integrações SAP');
    $page = kbsPage($sap, 'Pedido', 'texto antigo');

    expect(kbsSearch(['q' => 'girafa']))->not->toContain('Pedido');

    $page->update(['documentation' => 'agora fala de girafa']);

    expect(kbsSearch(['q' => 'girafa']))->toContain('Pedido');
});

/*
|--------------------------------------------------------------------------
| documentation:search-warm — no reader pays for a build
|--------------------------------------------------------------------------
*/

it('warms every caderno a reader can search, and the global index, ahead of the first search', function () {
    kbsPage(kbsNotebook('A Primeiro'), 'Um', 'termo comum');
    kbsPage(kbsNotebook('B Segundo'), 'Dois', 'termo comum');
    $shared = Notebook::factory()->create(['name' => 'Fornecedor', 'public_token' => 'tok123456789']);
    kbsPage($shared, 'Três', 'termo');
    $draft = kbsNotebook('Rascunho', published: false);
    kbsPage($draft, 'Quatro', 'termo');

    $this->artisan('documentation:search-warm')
        ->expectsOutputToContain('A Primeiro')
        ->expectsOutputToContain('Fornecedor')
        ->doesntExpectOutputToContain('Rascunho')
        ->expectsOutputToContain('3 built, 0 already warm')
        ->assertSuccessful();

    $search = app(DocumentationSearchService::class);
    $searchable = KnowledgeBaseController::searchableNotebooks();

    // The magic link reads the same per-caderno index; a caderno nobody can
    // reach is left alone.
    expect($search->isWarm($shared))->toBeTrue()
        ->and($search->isWarm($draft))->toBeFalse()
        ->and($search->warmAll($searchable))->toBe(['built' => false, 'pending' => []]);

    // A reader's search with no build budget at all still finds nothing cold.
    $noBudget = new class(app(DocumentationPageService::class)) extends DocumentationSearchService
    {
        protected function globalBuildBudget(): float
        {
            return 0.0;
        }
    };

    $result = $noBudget->searchAll($searchable, 'termo');

    expect($result['pending'])->toBe([])
        ->and($result['total'])->toBe(2);

    $this->artisan('documentation:search-warm')
        ->expectsOutputToContain('0 built, 3 already warm')
        ->assertSuccessful();
});

it('pushes back the expiry of what is already warm, so the daily run keeps it warm', function () {
    $this->freezeTime();
    $sap = kbsNotebook('Integrações SAP');
    kbsPage($sap, 'Pedido', 'texto');
    $search = app(DocumentationSearchService::class);

    $this->artisan('documentation:search-warm')->assertSuccessful();
    $this->travel(6)->days();
    $this->artisan('documentation:search-warm')->assertSuccessful();

    // Twelve days after it was built, six after the second run touched it.
    $this->travel(6)->days();

    expect($search->isWarm($sap))->toBeTrue()
        ->and($search->warmAll(KnowledgeBaseController::searchableNotebooks())['built'])->toBeFalse();

    // A full TTL after the last run, it is gone: the TTL is what was pushed.
    $this->travel(2)->days();

    expect($search->isWarm($sap))->toBeFalse();
});

it('rebuilds a caderno whose content changed since it was warmed', function () {
    $sap = kbsNotebook('Integrações SAP');
    $page = kbsPage($sap, 'Pedido', 'texto antigo');

    $this->artisan('documentation:search-warm')->assertSuccessful();
    $page->update(['documentation' => 'texto novo']);

    $this->artisan('documentation:search-warm')
        ->expectsOutputToContain('1 built, 0 already warm')
        ->assertSuccessful();

    expect(app(DocumentationSearchService::class)->warmAll(KnowledgeBaseController::searchableNotebooks())['built'])
        ->toBeFalse();
});

/*
|--------------------------------------------------------------------------
| Where the palette is, and where it starts
|--------------------------------------------------------------------------
*/

it('puts the search in the landing top bar, starting on every caderno', function () {
    $sap = kbsNotebook('Integrações SAP');

    $this->actingAs(User::factory()->create())
        ->get(route('docs.index'))
        ->assertOk()
        ->assertSee('data-ak-docs-search-open', false)
        ->assertSee('data-ak-docs-search-url="' . route('docs.search') . '"', false)
        ->assertSee('data-ak-docs-search-notebook=""', false)
        ->assertSee('Todos os cadernos')
        ->assertSee('<option value="' . $sap->slug . '"', false);
});

it('starts the palette on the caderno being read', function () {
    $sap = kbsNotebook('Integrações SAP');
    $page = kbsPage($sap, 'Pedido', 'texto');
    kbsNotebook('Manual do Digibee');

    $this->actingAs(User::factory()->create())
        ->get(route('docs.page', [$sap, $page]))
        ->assertOk()
        ->assertSee('data-ak-docs-search-notebook="' . $sap->slug . '"', false)
        ->assertSee('<option value="' . $sap->slug . '" selected', false)
        ->assertSee('Manual do Digibee');
});

it('gives the magic link no caderno selector — its token grants one caderno', function () {
    $notebook = Notebook::factory()->create(['public_token' => 'tok123456789']);
    $page = kbsPage($notebook, 'Pedido', 'texto');

    $this->get(route('public.docs.page', ['tok123456789', $page->slug]))
        ->assertOk()
        ->assertSee('data-ak-docs-search', false)
        ->assertDontSee('data-ak-docs-search-notebook', false)
        ->assertDontSee('Todos os cadernos');
});

it('refuses `search` as a caderno slug, since docs/search is the palette', function () {
    $this->actingAs(User::factory()->create(['role' => UserRole::Admin->value]))
        ->postJson(route('notebooks.store'), ['name' => 'Search'])
        ->assertOk();

    expect(Notebook::where('name', 'Search')->sole()->slug)->not->toBe('search');
});
