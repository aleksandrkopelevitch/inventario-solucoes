<?php

use App\Contracts\Documentable;
use App\Enums\UserRole;
use App\Models\Diagram;
use App\Models\DocumentationPage;
use App\Models\Notebook;
use App\Models\User;
use Illuminate\Foundation\Testing\LazilyRefreshDatabase;

uses(LazilyRefreshDatabase::class);

/**
 * The internal knowledge base (`/docs`) — the SEMI-public surface: an account is
 * required, any account will do, and what it shows is what an admin published.
 *
 * Most of what is asserted here is a refusal, because the interesting half of
 * this feature is everything a `Reader` must NOT reach: the inventory, an
 * unpublished caderno, and the media of one.
 */
function reader(): User
{
    return User::factory()->reader()->create();
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
    foreach ([UserRole::Reader, UserRole::Viewer, UserRole::Writer, UserRole::Admin] as $role) {
        $this->actingAs(User::factory()->create(['role' => $role]))
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

it('keeps a reader out of the whole inventory and sends them to /docs', function () {
    $reader = reader();

    foreach (['solutions.index', 'people.index', 'companies.index', 'notebooks.index', 'diagrams.index', 'flowspec.index', 'submissions.index', 'profile.show'] as $route) {
        $this->actingAs($reader)
            ->get(route($route))
            ->assertRedirect(route('docs.index'));
    }
});

it('answers a reader with 403 rather than a redirect when the caller wanted JSON', function () {
    // A redirect to an HTML page is unparseable to `ajax-slot.js`; a status is
    // not.
    $this->actingAs(reader())
        ->getJson(route('solutions.index'))
        ->assertForbidden();
});

it('sends a reader to /docs from the app root', function () {
    $this->actingAs(reader())->get('/')->assertRedirect(route('docs.index'));
});

it('lands a reader on /docs after a password login rather than on the app home', function () {
    // An account provisioned by SSO has no usable password, but one handed the
    // Reader role by an admin does — and must not be dropped on a screen that
    // immediately bounces them. No `actingAs` here: `login.store` is in the
    // `guest` group, so an authenticated session never reaches it.
    $reader = reader();
    $reader->forceFill(['password' => 'segredo-123'])->save();

    $this->postJson(route('login.store'), ['email' => $reader->email, 'password' => 'segredo-123'])
        ->assertOk()
        ->assertJson(['redirect' => route('docs.index')]);
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
    // check `files.show` cannot make — it authorizes by collection name, which
    // a signed-in Reader satisfies for every caderno in the app.
    $other = publishedNotebook('Outro');
    pageIn($other, 'Página');

    $this->actingAs(reader())
        ->get(route('docs.file', [$other, $media]))
        ->assertNotFound();
});

it('refuses a reader the authenticated media route entirely', function () {
    $notebook = publishedNotebook();
    $page = pageIn($notebook, 'Com imagem');
    $media = $page->addMediaFromString('bytes')
        ->usingFileName('diagrama.png')
        ->toMediaCollection(Documentable::DOCS_COLLECTION);

    // `files.show` lives in the inventory group, so the Reader never reaches
    // the controller's own check at all.
    $this->actingAs(reader())
        ->get(route('files.show', $media))
        ->assertRedirect(route('docs.index'));
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

    // The trap this covers: `RevealPageSecretRequest` authorized through
    // `NotebookPolicy::view`, which says NO to a Reader — so every lock on
    // /docs refused the audience the surface exists for.
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

it('withholds the settings screen from everybody but an admin', function () {
    // 403 for all three, the Reader included: the screen sits under `/docs`,
    // which carries no `inventory` middleware (that is the whole point of the
    // group), so `NotebookPolicy::administerAny` is what answers here — one
    // rule for everybody who is not an admin, rather than a refusal whose shape
    // depends on which tier asked.
    foreach ([UserRole::Reader, UserRole::Viewer, UserRole::Writer] as $role) {
        $this->actingAs(User::factory()->create(['role' => $role]))
            ->get(route('docs.settings'))
            ->assertForbidden();
    }

    $this->actingAs(User::factory()->create(['role' => UserRole::Admin]))
        ->get(route('docs.settings'))
        ->assertOk();
});
