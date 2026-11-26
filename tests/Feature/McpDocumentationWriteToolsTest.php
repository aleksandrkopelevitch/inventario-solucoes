<?php

use App\Enums\AccessModule;
use App\Mcp\OAuth;
use App\Models\DocumentationPage;
use App\Models\McpToken;
use App\Models\McpWrite;
use App\Models\Notebook;
use App\Models\Solution;
use App\Models\User;
use App\Services\DocumentationPageService;
use Illuminate\Foundation\Testing\LazilyRefreshDatabase;
use Laravel\Passport\Passport;

uses(LazilyRefreshDatabase::class);

/** An Editor of the documentation, connected with writing authorized. */
function docsWriter(): User
{
    return User::factory()->editor(AccessModule::Documentation)->create();
}

/** One `tools/call` as `$user`, decoded. @return array{isError: bool, text: string, data: ?array, content: array} */
function docTool(User $user, string $name, array $arguments = [], array $scopes = [OAuth::SCOPE, OAuth::WRITE_SCOPE]): array
{
    Passport::actingAs($user, $scopes);

    $result = test()->postJson(route('mcp.handle'), [
        'jsonrpc' => '2.0', 'id' => 1, 'method' => 'tools/call',
        'params'  => ['name' => $name, 'arguments' => $arguments],
    ])->assertOk()->json('result');

    return [
        'isError' => $result['isError'],
        'text'    => $result['content'][0]['text'],
        'data'    => json_decode($result['content'][0]['text'], true),
        'content' => $result['content'],
    ];
}

function mcpDocPage(string $markdown = '', array $attributes = [], ?Notebook $notebook = null): DocumentationPage
{
    $notebook ??= Notebook::factory()->create(['name' => 'Manual', 'slug' => 'manual']);

    return DocumentationPage::factory()->for($notebook)->create($attributes + ['title' => 'Página', 'slug' => 'pagina', 'documentation' => $markdown]);
}

/** The version get_documentation_page hands out — what update_page must send back. */
function readVersion(User $user, string $notebook, string $page): string
{
    return docTool($user, 'get_documentation_page', ['notebook' => $notebook, 'page' => $page])['data']['version'];
}

/* ------------------------------------------------------------------ */
/*  Who sees what */
/* ------------------------------------------------------------------ */

it('shows the documentation write tools only to a granted Documentation Editor', function () {
    $list = function (User $user, array $scopes = [OAuth::SCOPE, OAuth::WRITE_SCOPE]) {
        Passport::actingAs($user, $scopes);

        return collect(test()->postJson(route('mcp.handle'), ['jsonrpc' => '2.0', 'id' => 1, 'method' => 'tools/list'])->json('result.tools'))->pluck('name');
    };

    expect($list(docsWriter()))->toContain('create_notebook', 'update_page', 'place_page')
        ->and($list(User::factory()->editor(AccessModule::Catalog)->create()))->not->toContain('create_notebook')
        ->and($list(docsWriter(), [OAuth::SCOPE]))->not->toContain('update_page');
});

it('lets a writing connection read unpublished cadernos, marked as such, and nobody else', function () {
    mcpDocPage('Rascunho interno');   // in an unpublished caderno

    $writer = docTool(docsWriter(), 'list_notebooks');
    expect($writer['data']['notebooks'][0])->toMatchArray(['slug' => 'manual', 'published' => false]);

    expect(docTool(docsWriter(), 'get_documentation_page', ['notebook' => 'manual', 'page' => 'pagina'])['isError'])->toBeFalse();

    // A read-only connection, and a token, still get the one not-found sentence.
    $reader = docTool(User::factory()->reader()->create(), 'get_notebook', ['notebook' => 'manual']);
    expect($reader['isError'])->toBeTrue()->and($reader['text'])->toContain('Nenhum caderno publicado');

    $token = test()->postJson(route('mcp.handle'), [
        'jsonrpc' => '2.0', 'id' => 1, 'method' => 'tools/call',
        'params'  => ['name' => 'get_notebook', 'arguments' => ['notebook' => 'manual']],
    ], ['Authorization' => 'Bearer ' . McpToken::mint('Script')['plain']])->json('result');
    expect($token['isError'])->toBeTrue();
});

it('hands out the page version', function () {
    $page = mcpDocPage('Texto');

    expect(readVersion(docsWriter(), 'manual', 'pagina'))->toBe($page->version())->toHaveLength(32);
});

/* ------------------------------------------------------------------ */
/*  Cadernos */
/* ------------------------------------------------------------------ */

it('creates an unpublished caderno with its solutions, and refuses a duplicate name', function () {
    Solution::factory()->create(['slug' => 'sap']);
    $writer = docsWriter();

    $created = docTool($writer, 'create_notebook', ['name' => 'Integrações SAP', 'solutions' => ['sap']]);

    expect($created['isError'])->toBeFalse()
        ->and($created['data']['notebook'])->toMatchArray(['slug' => 'integracoes-sap', 'published' => false, 'solutions' => ['sap']]);

    expect(McpWrite::sole()->after)->toMatchArray(['name' => 'Integrações SAP', 'solutions' => ['sap']]);

    $other = docTool(docsWriter(), 'create_notebook', ['name' => 'integracoes sap']);
    expect($other['isError'])->toBeTrue()->and($other['text'])->toContain('integracoes-sap');
});

it('replaces the solutions set, keeps it on null and empties it on []', function () {
    Solution::factory()->create(['slug' => 'sap']);
    Solution::factory()->create(['slug' => 'vtex']);
    $notebook = Notebook::factory()->create(['slug' => 'manual']);
    $notebook->solutions()->sync(Solution::where('slug', 'sap')->pluck('id'));
    $writer = docsWriter();

    docTool($writer, 'update_notebook', ['slug' => 'manual', 'solutions' => ['vtex']]);
    expect($notebook->solutions()->pluck('slug')->all())->toBe(['vtex']);

    docTool($writer, 'update_notebook', ['slug' => 'manual', 'name' => 'Manual novo', 'solutions' => null]);
    expect($notebook->solutions()->pluck('slug')->all())->toBe(['vtex'])
        ->and($notebook->refresh()->name)->toBe('Manual novo');

    docTool($writer, 'update_notebook', ['slug' => 'manual', 'solutions' => []]);
    expect($notebook->solutions()->count())->toBe(0);

    expect(docTool($writer, 'update_notebook', ['slug' => 'manual', 'solutions' => ['ninguem']])['isError'])->toBeTrue();
});

/* ------------------------------------------------------------------ */
/*  Pages */
/* ------------------------------------------------------------------ */

it('creates pages at the root, under a parent and at a position, with authorship', function () {
    $writer = docsWriter();
    Notebook::factory()->create(['slug' => 'manual']);

    docTool($writer, 'create_page', ['notebook' => 'manual', 'title' => 'Visão geral']);
    docTool($writer, 'create_page', ['notebook' => 'manual', 'title' => 'Instalação', 'content' => "# Passos\n\nUm."]);
    $first = docTool($writer, 'create_page', ['notebook' => 'manual', 'title' => 'Antes de tudo', 'position' => 0]);
    $child = docTool($writer, 'create_page', ['notebook' => 'manual', 'title' => 'Requisitos', 'parent' => 'instalacao']);

    expect($first['data']['page'])->toMatchArray(['slug' => 'antes-de-tudo', 'position' => 0])
        ->and($child['data']['page'])->toMatchArray(['parent' => 'instalacao', 'depth' => 1]);

    $page = DocumentationPage::where('slug', 'instalacao')->first();
    expect($page->documentation)->toBe("# Passos\n\nUm.")
        ->and($page->created_by_id)->toBe($writer->id);

    // Same title in the same place is a duplicate; under another parent it is not.
    expect(docTool($writer, 'create_page', ['notebook' => 'manual', 'title' => 'Requisitos', 'parent' => 'visao-geral'])['isError'])->toBeFalse()
        ->and(docTool(docsWriter(), 'create_page', ['notebook' => 'manual', 'title' => 'requisitos', 'parent' => 'instalacao'])['isError'])->toBeTrue();
});

it('refuses a page past the depth cap', function () {
    $notebook = Notebook::factory()->create(['slug' => 'manual']);
    $parent = null;

    foreach (range(1, DocumentationPage::MAX_DEPTH) as $level) {
        $parent = app(DocumentationPageService::class)->create($notebook, "Nível {$level}", $parent);
    }

    $result = docTool(docsWriter(), 'create_page', ['notebook' => 'manual', 'title' => 'Fundo', 'parent' => $parent->slug]);

    expect($result['isError'])->toBeTrue()->and($result['text'])->toContain('último nível');
});

it('refuses a stale version and changes nothing', function () {
    $page = mcpDocPage('Original');
    $writer = docsWriter();
    $version = readVersion($writer, 'manual', 'pagina');

    $page->update(['documentation' => 'Alguém editou no app']);

    $result = docTool($writer, 'update_page', ['notebook' => 'manual', 'page' => 'pagina', 'version' => $version, 'content' => 'Versão do chat']);

    expect($result['isError'])->toBeTrue()->and($result['text'])->toContain('mudou desde a sua leitura')
        ->and($page->refresh()->documentation)->toBe('Alguém editou no app');
});

it('round-trips a masked body with every protected value intact', function () {
    $page = mcpDocPage("Header: {% secret id=\"k3j9x2ab\" %}Bearer abc123{% endsecret %}\n\nTexto.");
    $writer = docsWriter();

    $read = docTool($writer, 'get_documentation_page', ['notebook' => 'manual', 'page' => 'pagina']);
    $masked = $read['content'][1]['text'];
    expect($masked)->not->toContain('abc123')->toContain('[[SECRET-k3j9x2ab]]');

    $result = docTool($writer, 'update_page', [
        'notebook' => 'manual', 'page' => 'pagina', 'version' => $read['data']['version'],
        'content'  => str_replace('Texto.', 'Texto revisado.', $masked),
    ]);

    expect($result['isError'])->toBeFalse()
        ->and($page->refresh()->documentation)->toContain('Bearer abc123')->toContain('Texto revisado.')
        ->and($page->updated_by_id)->toBe($writer->id);

    // The log holds the real body, encrypted; the screen shows it masked.
    expect(McpWrite::sole()->after['documentation'])->toContain('Bearer abc123');
});

it('refuses to drop a protected value unless the call names it', function () {
    mcpDocPage('Senha: {% secret id="k3j9x2ab" %}hunter2{% endsecret %}');
    $writer = docsWriter();
    $args = ['notebook' => 'manual', 'page' => 'pagina', 'version' => readVersion($writer, 'manual', 'pagina'), 'content' => 'Sem senha.'];

    $refused = docTool($writer, 'update_page', $args);
    expect($refused['isError'])->toBeTrue()->and($refused['text'])->toContain('[[SECRET-k3j9x2ab]]');

    expect(docTool($writer, 'update_page', $args + ['remove_secrets' => ['[[SECRET-k3j9x2ab]]']])['isError'])->toBeFalse()
        ->and(DocumentationPage::sole()->documentation)->toBe('Sem senha.');
});

it('refuses to drop a cited diagram or file unless the call names it', function () {
    mcpDocPage("Veja:\n\n{% diagram slug=\"fluxo-pedidos\" %}\n\n<a href=\"/files/812\">anexo</a>");
    $writer = docsWriter();
    $args = ['notebook' => 'manual', 'page' => 'pagina', 'version' => readVersion($writer, 'manual', 'pagina'), 'content' => 'Veja: nada.'];

    $refused = docTool($writer, 'update_page', $args);
    expect($refused['text'])->toContain('diagram:fluxo-pedidos')->toContain('file:812');

    expect(docTool($writer, 'update_page', $args + ['confirm_removals' => ['diagram:fluxo-pedidos']])['isError'])->toBeTrue();
    expect(docTool($writer, 'update_page', $args + ['confirm_removals' => ['diagram:fluxo-pedidos', 'file:812']])['isError'])->toBeFalse();
});

it('refuses a sharp shrink, and accepts a confirmed band at or below the real one', function () {
    mcpDocPage(str_repeat('Parágrafo longo de documentação. ', 100));   // ~3300 chars
    $writer = docsWriter();
    $short = str_repeat('Parágrafo longo de documentação. ', 31);     // ~31% kept
    $args = fn () => ['notebook' => 'manual', 'page' => 'pagina', 'version' => readVersion($writer, 'manual', 'pagina'), 'content' => $short];

    $refused = docTool($writer, 'update_page', $args());
    expect($refused['isError'])->toBeTrue()->and($refused['text'])->toContain('shrink:30%');

    // Confirming a bigger share than is really kept is not confirming this loss.
    expect(docTool($writer, 'update_page', $args() + ['confirm_removals' => ['shrink:50%']])['isError'])->toBeTrue();
    expect(docTool($writer, 'update_page', $args() + ['confirm_removals' => ['shrink:30%']])['isError'])->toBeFalse();
});

it('renames a page without touching its slug or its version', function () {
    $page = mcpDocPage('Texto');
    $writer = docsWriter();
    $version = readVersion($writer, 'manual', 'pagina');

    docTool($writer, 'update_page', ['notebook' => 'manual', 'page' => 'pagina', 'version' => $version, 'title' => 'Novo título']);

    expect($page->refresh())->title->toBe('Novo título')->slug->toBe('pagina')
        ->and($page->version())->toBe($version);
});

it('places a page anywhere in one call, and refuses a cycle or the depth cap', function () {
    $notebook = Notebook::factory()->create(['slug' => 'manual']);
    $service = app(DocumentationPageService::class);
    $a = $service->create($notebook, 'A');
    $b = $service->create($notebook, 'B');
    $a1 = $service->create($notebook, 'A1', $a);
    $writer = docsWriter();

    expect(docTool($writer, 'place_page', ['notebook' => 'manual', 'page' => 'a', 'parent' => 'a1'])['text'])->toContain('dentro dela mesma');

    $moved = docTool($writer, 'place_page', ['notebook' => 'manual', 'page' => 'b', 'parent' => 'a', 'position' => 0]);
    expect($moved['data']['page'])->toMatchArray(['parent' => 'a', 'position' => 0, 'depth' => 1])
        ->and($service->indexAmongSiblings($a1->refresh()))->toBe(1);

    // And back, through the write log.
    $this->actingAs(User::factory()->admin()->create(), 'web')
        ->postJson(route('mcp-writes.revert', McpWrite::sole()))
        ->assertJsonPath('type', 'success');

    expect($b->refresh()->parent_id)->toBeNull()->and($service->indexAmongSiblings($b))->toBe(1);
});

it('reverts a page body, and refuses to delete a created page that gained subpages', function () {
    $page = mcpDocPage('Antes');
    $writer = docsWriter();
    $admin = User::factory()->admin()->create();

    docTool($writer, 'update_page', ['notebook' => 'manual', 'page' => 'pagina', 'version' => $page->version(), 'content' => 'Depois']);
    $this->actingAs($admin, 'web')->postJson(route('mcp-writes.revert', McpWrite::sole()))->assertJsonPath('type', 'success');
    expect($page->refresh()->documentation)->toBe('Antes');

    docTool($writer, 'create_page', ['notebook' => 'manual', 'title' => 'Nova']);
    docTool($writer, 'create_page', ['notebook' => 'manual', 'title' => 'Filha', 'parent' => 'nova']);
    $create = McpWrite::where('tool', 'create_page')->orderBy('id')->first();

    $this->actingAs($admin, 'web')->postJson(route('mcp-writes.revert', $create))->assertJsonPath('type', 'warning');
    expect(DocumentationPage::where('slug', 'nova')->exists())->toBeTrue();
});

/* ------------------------------------------------------------------ */
/*  The browser editor carries the version too */
/* ------------------------------------------------------------------ */

it('refuses an editor save based on an older version with a 409, and answers the new version otherwise', function () {
    $page = mcpDocPage('Original');
    $editor = docsWriter();
    $opened = $page->version();

    $saved = $this->actingAs($editor, 'web')
        ->patchJson(route('notebooks.pages.update', [$page->notebook, $page]), ['documentation' => 'Meu texto', 'base_version' => $opened])
        ->assertOk();

    expect($saved->json('version'))->toBe(md5('Meu texto'));

    // Somebody (a chat) saves in between: the tab's old version is stale now.
    $page->refresh()->update(['documentation' => 'Texto do chat']);

    $this->actingAs($editor, 'web')
        ->patchJson(route('notebooks.pages.update', [$page->notebook, $page]), ['documentation' => 'Sobrescrita', 'base_version' => $saved->json('version')])
        ->assertStatus(409)
        ->assertJsonPath('conflict', true);

    expect($page->refresh()->documentation)->toBe('Texto do chat');

    // A tab opened before this shipped sends no version, and still saves.
    $this->actingAs($editor, 'web')
        ->patchJson(route('notebooks.pages.update', [$page->notebook, $page]), ['documentation' => 'Aba antiga'])
        ->assertOk();
});

it('renders the version into the editor', function () {
    $page = mcpDocPage('Texto');

    $this->actingAs(docsWriter(), 'web')
        ->get(route('notebooks.pages.edit', [$page->notebook, $page]))
        ->assertOk()
        ->assertSee($page->version());
});

it('shows page and caderno writes on the admin screen, with page bodies masked', function () {
    mcpDocPage("Token: {% secret id=\"k3j9x2ab\" %}hunter2{% endsecret %}\n\nAntes");
    $writer = docsWriter();
    $read = docTool($writer, 'get_documentation_page', ['notebook' => 'manual', 'page' => 'pagina']);

    docTool($writer, 'update_page', [
        'notebook' => 'manual', 'page' => 'pagina', 'version' => $read['data']['version'],
        'content'  => str_replace('Antes', 'Depois', $read['content'][1]['text']),
    ]);
    docTool($writer, 'create_notebook', ['name' => 'Outro caderno']);

    $this->actingAs(User::factory()->admin()->create(), 'web')
        ->get(route('mcp-writes.index'))
        ->assertOk()
        ->assertSee('Editar uma página')
        ->assertSee('Criar um caderno')
        ->assertSee('Depois')
        ->assertDontSee('hunter2');
});

/* ------------------------------------------------------------------ */
/*  Findings of the phase-2 review */
/* ------------------------------------------------------------------ */

it('skips reverting a placement that would make a cycle', function () {
    $notebook = Notebook::factory()->create(['slug' => 'manual']);
    $service = app(DocumentationPageService::class);
    $x = $service->create($notebook, 'X');
    $a = $service->create($notebook, 'A', $x);

    docTool(docsWriter(), 'place_page', ['notebook' => 'manual', 'page' => 'a', 'position' => 0]);
    // Somebody then nests X under A in the app.
    $service->place($x->refresh(), $a->refresh(), 0);

    $this->actingAs(User::factory()->admin()->create(), 'web')
        ->postJson(route('mcp-writes.revert', McpWrite::sole()))
        ->assertJsonPath('type', 'warning');

    expect($a->refresh()->parent_id)->toBeNull()->and($x->refresh()->parent_id)->toBe($a->id);
});

it('does not let shrink:0% pre-confirm a loss', function () {
    mcpDocPage(str_repeat('Parágrafo longo de documentação. ', 100));
    $writer = docsWriter();

    $result = docTool($writer, 'update_page', [
        'notebook' => 'manual', 'page' => 'pagina', 'version' => readVersion($writer, 'manual', 'pagina'),
        'content'  => '[restante inalterado]', 'confirm_removals' => ['shrink:0%'],
    ]);

    // 21 of ~3300 characters is band 0, so 0% is the real band here — the
    // point is that a band BELOW the real one by more than a step is refused:
    expect($result['isError'])->toBeFalse();

    mcpDocPage(str_repeat('Parágrafo longo de documentação. ', 100), ['slug' => 'outra', 'title' => 'Outra'], Notebook::where('slug', 'manual')->first());
    $half = str_repeat('Parágrafo longo de documentação. ', 45);

    expect(docTool($writer, 'update_page', [
        'notebook' => 'manual', 'page' => 'outra', 'version' => readVersion($writer, 'manual', 'outra'),
        'content'  => $half, 'confirm_removals' => ['shrink:0%'],
    ])['isError'])->toBeTrue();
});

it('counts a diagram citation moved into a sentence as removed, and tracks any file src', function () {
    mcpDocPage("{% diagram slug=\"fluxo\" %}\n\n{% file src=\"https://example.com/a.pdf\" %}");
    $writer = docsWriter();

    $refused = docTool($writer, 'update_page', [
        'notebook' => 'manual', 'page' => 'pagina', 'version' => readVersion($writer, 'manual', 'pagina'),
        'content'  => 'Veja {% diagram slug="fluxo" %} aqui.',
    ]);

    expect($refused['text'])->toContain('diagram:fluxo')->toContain('file:https://example.com/a.pdf');
});

it('accepts a numbered legacy marker as keeping its value', function () {
    $page = mcpDocPage('A {% secret id="k3j9x2ab" %}hunter2{% endsecret %} B');
    $writer = docsWriter();

    $result = docTool($writer, 'update_page', [
        'notebook' => 'manual', 'page' => 'pagina', 'version' => readVersion($writer, 'manual', 'pagina'),
        'content'  => 'A {% secret %}[[SECRET-1]]{% endsecret %} B revisado',
    ]);

    expect($result['isError'])->toBeFalse()->and($page->refresh()->documentation)->toContain('hunter2');
});

it('links search results of an unpublished caderno to the editor, not to /docs', function () {
    mcpDocPage("# Título\n\nTermo raro xylofone.");

    $result = docTool(docsWriter(), 'search_documentation', ['query' => 'xylofone', 'notebook' => 'manual']);

    expect($result['data']['results'][0]['url'])->toContain('/notebooks/manual/');
});

it('refuses to delete a created caderno that gained context documents', function () {
    docTool(docsWriter(), 'create_notebook', ['name' => 'Novo']);
    Notebook::where('slug', 'novo')->first()
        ->addMediaFromString('contexto')->usingFileName('ctx.txt')->toMediaCollection(Notebook::CONTEXT_COLLECTION);

    $this->actingAs(User::factory()->admin()->create(), 'web')
        ->postJson(route('mcp-writes.revert', McpWrite::sole()))
        ->assertJsonPath('type', 'warning');

    expect(Notebook::where('slug', 'novo')->exists())->toBeTrue();
});

/* ------------------------------------------------------------------ */
/*  Diagrams and scenes written through the MCP */
/* ------------------------------------------------------------------ */

function mcpUml(string $title, array $participants = ['a', 'b']): string
{
    return "{% uml type=\"sequence\" title=\"{$title}\" %}\n" . json_encode([
        'participants' => array_map(fn (string $id) => ['id' => $id, 'label' => strtoupper($id)], $participants),
        'messages'     => [['from' => $participants[0], 'to' => end($participants), 'label' => 'chama']],
    ]) . "\n{% enduml %}";
}

it('creates a page with a UML diagram and a scene, and refuses one with an error, naming each problem', function () {
    Notebook::factory()->create(['name' => 'Manual', 'slug' => 'manual']);
    $scene = "{% scene type=\"steps\" %}\n{% step title=\"Um\" %}\na\n{% endstep %}\n{% step title=\"Dois\" %}\nb\n{% endstep %}\n{% endscene %}";

    $ok = docTool(docsWriter(), 'create_page', ['notebook' => 'manual', 'title' => 'Fluxo', 'content' => mcpUml('Pedido') . "\n\n" . $scene]);
    expect($ok['isError'])->toBeFalse();

    $bad = docTool(docsWriter(), 'create_page', ['notebook' => 'manual', 'title' => 'Outro', 'content' => mcpUml('Quebrado', ['a'])]);
    expect($bad['isError'])->toBeTrue()
        ->and($bad['text'])->toContain('Nada foi salvo')
        ->toContain('Diagrama UML "Quebrado": Um diagrama de sequência precisa de 2 a 12 participantes')
        ->toContain('get_code_documentation_guide')
        ->and(DocumentationPage::where('title', 'Outro')->exists())->toBeFalse();
});

it('does not re-judge a figure already on the page, and asks to confirm dropping a UML diagram', function () {
    $legacy = "{% scene type=\"steps\" %}\n{% step title=\"Só uma\" %}x{% endstep %}\n{% endscene %}";
    mcpDocPage("Intro\n\n{$legacy}\n\n" . mcpUml('Pedido'));
    $user = docsWriter();

    // An edit elsewhere keeps the (invalid, hand-made) scene and passes.
    $kept = docTool($user, 'update_page', ['notebook' => 'manual', 'page' => 'pagina', 'version' => readVersion($user, 'manual', 'pagina'),
        'content'                                     => "Intro revisada\n\n{$legacy}\n\n" . mcpUml('Pedido')]);
    expect($kept['isError'])->toBeFalse();

    $dropped = docTool($user, 'update_page', ['notebook' => 'manual', 'page' => 'pagina', 'version' => readVersion($user, 'manual', 'pagina'),
        'content'                                        => "Intro revisada\n\n{$legacy}"]);
    expect($dropped['isError'])->toBeTrue()->and($dropped['text'])->toContain('"uml:Pedido"');

    $confirmed = docTool($user, 'update_page', ['notebook' => 'manual', 'page' => 'pagina', 'version' => readVersion($user, 'manual', 'pagina'),
        'content'                                          => "Intro revisada\n\n{$legacy}", 'confirm_removals' => ['uml:Pedido']]);
    expect($confirmed['isError'])->toBeFalse();
});

it('hands out the code documentation guide, with the caderno it will write into', function () {
    mcpDocPage('Texto', ['title' => 'Visão geral', 'slug' => 'visao-geral']);

    $guide = docTool(docsWriter(), 'get_code_documentation_guide', ['notebook' => 'manual']);

    expect($guide['isError'])->toBeFalse()
        ->and($guide['text'])->toContain('{% uml type="sequence"')
        ->toContain('Mermaid não é renderizado')
        ->toContain('Caderno "manual" hoje')
        ->toContain('visao-geral')
        ->not->toContain('NÃO escreve');

    expect(docTool(User::factory()->reader()->create(), 'get_code_documentation_guide')['text'])->toContain('NÃO escreve');
});

it('asks to confirm dropping one of two diagrams that share a label', function () {
    $twin = mcpUml('');
    mcpDocPage("Intro\n\n{$twin}\n\n{$twin}");
    $user = docsWriter();

    $dropped = docTool($user, 'update_page', ['notebook' => 'manual', 'page' => 'pagina', 'version' => readVersion($user, 'manual', 'pagina'),
        'content'                                        => "Intro\n\n{$twin}"]);

    expect($dropped['isError'])->toBeTrue()->and($dropped['text'])->toContain('"uml:sequence#2"');
});

it('tells a reader what a uml or scene block is and where its syntax lives', function () {
    Passport::actingAs(docsWriter(), [OAuth::SCOPE, OAuth::WRITE_SCOPE]);

    $description = collect(test()->postJson(route('mcp.handle'), ['jsonrpc' => '2.0', 'id' => 1, 'method' => 'tools/list'])->json('result.tools'))
        ->firstWhere('name', 'get_documentation_page')['description'];

    expect($description)->toContain('{% uml type=')->toContain('{% scene type=')->toContain('get_code_documentation_guide');
});

it('answers the editor block with the problems the page would show, and only to an editor', function () {
    $page = mcpDocPage('Texto');
    $url = route('notebooks.pages.uml', [$page->notebook, $page]);
    $bad = ['type' => 'class', 'source' => json_encode(['nodes' => [['id' => 'a', 'kind' => 'class', 'name' => 'A']]])];

    test()->actingAs(docsWriter())->postJson($url, $bad)
        ->assertOk()->assertJsonPath('problems.0', fn (string $problem) => str_contains($problem, '"at"'));

    $good = ['nodes' => [['id' => 'a', 'kind' => 'class', 'name' => 'A', 'at' => [0, 0]]]];
    test()->actingAs(docsWriter())->postJson($url, ['type' => 'class', 'source' => json_encode($good)])
        ->assertOk()->assertExactJson(['problems' => [], 'bindings' => [], 'hints' => []]);

    test()->actingAs(User::factory()->reader()->create())->postJson($url, $bad)->assertForbidden();
});
