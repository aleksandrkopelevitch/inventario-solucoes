<?php

use App\Contracts\Documentable;
use App\Models\Diagram;
use App\Models\DocumentationPage;
use App\Models\Notebook;
use App\Models\User;
use Database\Seeders\KnowledgeBaseHomeSeeder;
use Illuminate\Foundation\Testing\LazilyRefreshDatabase;

uses(LazilyRefreshDatabase::class);

/**
 * The internal knowledge base (`/docs`) — the SEMI-public surface: an account is
 * required, any account will do, and what it shows is what an admin published.
 *
 * Most of what is asserted here is a refusal, because the interesting half of
 * this feature is what `/docs` must NOT show: an unpublished caderno, and the
 * media of one.
 */
function reader(): User
{
    return User::factory()->create();
}

function publishedNotebook(string $name = 'Manual do Digibee'): Notebook
{
    return Notebook::factory()->published()->create(['name' => $name, 'slug' => Str::slug($name)]);
}

function pageIn(Notebook $notebook, string $title, string $body = 'Conteúdo.'): DocumentationPage
{
    $page = new DocumentationPage([
        'title'         => $title,
        'slug'          => Str::slug($title),
        'documentation' => $body,
        'position'      => 0,
    ]);
    $page->notebook()->associate($notebook);
    $page->save();

    return $page;
}

it('lets a reader open a published caderno and its pages', function () {
    $notebook = publishedNotebook();
    $page = pageIn($notebook, 'Primeiros passos', 'Comece por aqui.');

    $this->actingAs(reader())
        ->get(route('docs.notebook', $notebook))
        ->assertOk()
        ->assertSee('Comece por aqui.');

    $this->actingAs(reader())
        ->get(route('docs.page', [$notebook, $page]))
        ->assertOk()
        ->assertSee('Primeiros passos');
});

it('404s an unpublished caderno for everybody, the admin included', function () {
    $notebook = Notebook::factory()->create();
    pageIn($notebook, 'Rascunho');

    // The point of the strict version: `/docs` exists so that "what has been
    // published" can be answered by looking at it, and a surface that shows
    // more to whoever decides what is on it cannot answer that.
    foreach ([User::factory()->create(), User::factory()->editor()->create(), User::factory()->admin()->create()] as $user) {
        $this->actingAs($user)
            ->get(route('docs.notebook', $notebook))
            ->assertNotFound();
    }
});

it('lists only published cadernos on the landing and in the switcher', function () {
    $published = publishedNotebook('Caderno publicado');
    $hidden = Notebook::factory()->create(['name' => 'Caderno escondido']);
    pageIn($published, 'Página');
    pageIn($hidden, 'Página');

    $this->actingAs(reader())
        ->get(route('docs.index'))
        ->assertOk()
        ->assertSee('Caderno publicado')
        ->assertDontSee('Caderno escondido');

    $this->actingAs(reader())
        ->get(route('docs.notebook', $published))
        ->assertOk()
        ->assertDontSee('Caderno escondido');
});

it('404s a page that belongs to another caderno', function () {
    $mine = publishedNotebook('Meu caderno');
    $other = publishedNotebook('Outro caderno');
    pageIn($mine, 'Página');
    $foreign = pageIn($other, 'Alheia');

    // Scoped bindings: a page slug is unique per caderno, never globally, so a
    // global binding would have found this one and rendered it under the wrong
    // owner.
    $this->actingAs(reader())
        ->get(url("/docs/{$mine->slug}/{$foreign->slug}"))
        ->assertNotFound();
});

// Every account reads every module now (App\Enums\AccessModule) — the tier
// that reached `/docs` and nothing else is gone: a brand-new account reads the
// catalog and the documentation by default (AccessModule::defaultLevel()), and
// the Especialista and the Comitê wait for an admin.
it('lets a brand-new account read the catalog and the documentation as well as /docs', function () {
    $reader = reader();

    foreach (['solutions.index', 'people.index', 'companies.index', 'notebooks.index', 'diagrams.index', 'solutions.map', 'profile.show'] as $route) {
        $this->actingAs($reader)->get(route($route))->assertOk();
    }

    foreach (['flowspec.index', 'submissions.index'] as $route) {
        $this->actingAs($reader)->get(route($route))->assertForbidden();
    }
});

it('lands a password login on the app home', function () {
    $reader = reader();
    $reader->forceFill(['password' => 'segredo-123'])->save();

    $this->postJson(route('login.store'), ['email' => $reader->email, 'password' => 'segredo-123'])
        ->assertOk()
        ->assertJson(['redirect' => route('profile.show')]);
});

it('serves media of the caderno being read and nothing else', function () {
    $notebook = publishedNotebook();
    $page = pageIn($notebook, 'Com imagem');
    $media = $page->addMediaFromString('bytes')
        ->usingFileName('diagrama.png')
        ->toMediaCollection(Documentable::DOCS_COLLECTION);

    $this->actingAs(reader())
        ->get(route('docs.file', [$notebook, $media]))
        ->assertOk();

    // The same media asked for through ANOTHER published caderno. This is the
    // check `files.show` cannot make — it authorizes by collection name, for
    // every caderno in the app.
    $other = publishedNotebook('Outro');
    pageIn($other, 'Página');

    $this->actingAs(reader())
        ->get(route('docs.file', [$other, $media]))
        ->assertNotFound();
});

it('serves a diagram picture only to the caderno that cites it', function () {
    $diagram = Diagram::factory()->create(['slug' => 'fluxo-pedido']);
    $diagram->addMediaFromString('png-bytes')
        ->usingFileName('fluxo.png')
        ->toMediaCollection(Diagram::DIAGRAM_COLLECTION);

    $citing = publishedNotebook('Cita');
    pageIn($citing, 'Página', 'Veja {% diagram slug="fluxo-pedido" %} abaixo.');

    $silent = publishedNotebook('Não cita');
    pageIn($silent, 'Página', 'Sem citação nenhuma.');

    $this->actingAs(reader())
        ->get(route('docs.diagram', [$citing, $diagram]))
        ->assertOk();

    // Authorised by CITATION, not by the diagram — otherwise the route could be
    // walked to enumerate the drawing catalog.
    $this->actingAs(reader())
        ->get(route('docs.diagram', [$silent, $diagram]))
        ->assertNotFound();
});

it('resolves a page: link to the knowledge base address, not the editor one', function () {
    $notebook = publishedNotebook();
    $target = pageIn($notebook, 'Destino');
    pageIn($notebook, 'Origem', 'Veja a [página destino](page:' . $target->slug . ').');

    $response = $this->actingAs(reader())
        ->get(route('docs.page', [$notebook, 'origem']))
        ->assertOk();

    expect($response->getContent())
        ->toContain(route('docs.page', [$notebook, $target]))
        ->not->toContain(route('notebooks.pages.edit', [$notebook, $target]));
});

it('searches only the caderno being read', function () {
    $mine = publishedNotebook('Meu');
    pageIn($mine, 'Autenticação', 'Use um token bearer.');

    $other = publishedNotebook('Outro');
    pageIn($other, 'Autorização', 'Use um token bearer.');

    $response = $this->actingAs(reader())
        ->getJson(route('docs.search', $mine) . '?q=bearer')
        ->assertOk();

    expect($response->json('updatableSlots.0.content'))
        ->toContain('Autenticação')
        ->not->toContain('Autorização');
});

it('refuses search and media on an unpublished caderno too', function () {
    $notebook = Notebook::factory()->create();
    pageIn($notebook, 'Rascunho');

    // These are reached by id rather than by browsing, so publication has to be
    // re-asked on each of them and not only on the page render.
    $this->actingAs(reader())->getJson(route('docs.search', $notebook) . '?q=x')->assertNotFound();
});

it('lets a reader unlock a protected value with the caderno code', function () {
    $notebook = publishedNotebook();
    $page = pageIn($notebook, 'Credenciais', 'Header: {% secret %}Bearer abc123{% endsecret %}');

    // `/docs` authorizes the lock by PUBLICATION, not by the caderno's own
    // policy (RevealPageSecretRequest).
    $this->actingAs(reader())
        ->postJson(route('docs.secrets', [$notebook, $page, 'index' => 1]), ['code' => $notebook->secret_code])
        ->assertOk()
        ->assertJson(['value' => 'Bearer abc123']);
});

it('sends a guest to the login screen when SSO is off', function () {
    config(['services.azure.enabled' => false]);

    $notebook = publishedNotebook();
    pageIn($notebook, 'Página');

    $this->get(route('docs.notebook', $notebook))->assertRedirect(route('login.create'));
});

/*
 * The landing (`/docs`): the home caderno's first page beside a rail of every
 * published caderno.
 */
function homeNotebook(string $body = '# Bem-vindo' . "\n\nTexto da página inicial."): Notebook
{
    $home = publishedNotebook('Base de conhecimento');
    $home->forceFill(['is_home' => true])->save();
    pageIn($home, 'Bem-vindo', $body);

    return $home;
}

it('renders the home caderno on the landing, beside a filterable rail of the other cadernos', function () {
    homeNotebook();
    $other = publishedNotebook('Manual do SAP');
    pageIn($other, 'Página');

    $this->actingAs(reader())
        ->get(route('docs.index'))
        ->assertOk()
        ->assertSee('Texto da página inicial.')
        ->assertSee('data-ak-docs-switcher-input', false)
        ->assertSee('Filtrar cadernos')
        ->assertSee($other->knowledgeBaseUrl(), false)
        // The landing is the front door, not documentation to copy elsewhere.
        ->assertDontSee('data-ak-docs-copy', false);
});

it('leaves the home caderno out of the lists and gives it /docs as its address', function () {
    $home = homeNotebook();
    $other = publishedNotebook('Manual do SAP');
    pageIn($other, 'Página');

    expect($home->knowledgeBaseUrl())->toBe(route('docs.index'));

    // The switcher inside another caderno lists the others, not the landing.
    $this->actingAs(reader())
        ->get(route('docs.notebook', $other))
        ->assertOk()
        ->assertDontSee(route('docs.notebook', $home), false);

    // And its own caderno address sends the reader to the landing.
    $this->actingAs(reader())
        ->get(route('docs.notebook', $home))
        ->assertRedirect(route('docs.index'));
});

it('serves the landing pictures from the home caderno', function () {
    Storage::fake('public');
    $home = homeNotebook();
    $page = $home->pages()->first();
    $media = $page->addMediaFromString('<svg xmlns="http://www.w3.org/2000/svg"/>')
        ->usingFileName('figura.svg')
        ->toMediaCollection(Documentable::DOCS_COLLECTION);
    $page->update(['documentation' => '<figure><img src="/files/' . $media->id . '" alt="Figura"><figcaption>Figura</figcaption></figure>']);

    $this->actingAs(reader())
        ->get(route('docs.index'))
        ->assertOk()
        ->assertSee(route('docs.file', [$home, $media->id]), false);

    $this->actingAs(reader())
        ->get(route('docs.file', [$home, $media->id]))
        ->assertOk();
});

it('falls back to the cadernos as cards while the home is not published', function () {
    $home = homeNotebook();
    $home->forceFill(['published_at' => null])->save();
    $other = publishedNotebook('Manual do SAP');
    pageIn($other, 'Página');

    $this->actingAs(reader())
        ->get(route('docs.index'))
        ->assertOk()
        ->assertDontSee('Texto da página inicial.')
        ->assertSee('A documentação que o time de Arquitetura publicou')
        ->assertSee('Manual do SAP');
});

it('marks the home caderno on the cadernos catalog', function () {
    homeNotebook();

    $this->actingAs(User::factory()->admin()->create())
        ->get(route('notebooks.index'))
        ->assertOk()
        ->assertSee('Página inicial');
});

it('seeds the landing once and never overwrites it', function () {
    Storage::fake('public');
    $this->seed(KnowledgeBaseHomeSeeder::class);

    $home = Notebook::query()->home()->sole();
    $page = $home->pages()->sole();

    // Every figure became an ordinary image block over the page's own media.
    expect($page->documentation)
        ->not->toContain('{{figure:')
        ->toContain('<figure><img src="/files/')
        ->and($page->getMedia(Documentable::DOCS_COLLECTION))->toHaveCount(6);

    $page->update(['documentation' => 'Editado no caderno.']);
    $this->seed(KnowledgeBaseHomeSeeder::class);

    expect(Notebook::query()->where('is_home', true)->count())->toBe(1)
        ->and($page->fresh()->documentation)->toBe('Editado no caderno.');

    $this->actingAs(reader())
        ->get(route('docs.index'))
        ->assertOk()
        ->assertSee('Editado no caderno.');
});
