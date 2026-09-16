<?php

use App\Enums\PersonSolutionRole;
use App\Enums\UserRole;
use App\Mcp\ToolRegistry;
use App\Models\AttributeOption;
use App\Models\Company;
use App\Models\Diagram;
use App\Models\DocumentationPage;
use App\Models\McpToken;
use App\Models\Notebook;
use App\Models\Person;
use App\Models\Solution;
use App\Models\User;
use Illuminate\Foundation\Testing\LazilyRefreshDatabase;

uses(LazilyRefreshDatabase::class);

/**
 * One `tools/call`, decoded.
 *
 * @return array{isError: bool, text: string, data: array|null, content: array}
 */
function tool(string $name, array $arguments = []): array
{
    $response = test()->postJson(
        route('mcp.handle'),
        ['jsonrpc'       => '2.0', 'id' => 1, 'method' => 'tools/call', 'params' => ['name' => $name, 'arguments' => $arguments]],
        ['Authorization' => 'Bearer ' . McpToken::mint('Test')['plain']],
    )->assertOk();

    $result = $response->json('result');

    return [
        'isError' => $result['isError'],
        'text'    => collect($result['content'])->pluck('text')->implode("\n"),
        'data'    => json_decode($result['content'][0]['text'], true),
        'content' => $result['content'],
    ];
}

/** A page with content, in a caderno that IS published. */
function publishedPage(string $markdown, string $title = 'Página'): DocumentationPage
{
    $notebook = Notebook::factory()->create(['name' => 'Manual', 'slug' => 'manual']);
    $notebook->forceFill(['published_at' => now()])->save();

    return DocumentationPage::factory()->for($notebook)->create([
        'title'         => $title,
        'documentation' => $markdown,
    ]);
}

/* ------------------------------------------------------------------ */
/*  Solutions */
/* ------------------------------------------------------------------ */

it('searches solutions by name, ignoring accents and case', function () {
    Solution::factory()->create(['name' => 'Gestão de Operações']);
    Solution::factory()->create(['name' => 'BigQuery']);

    // The whole reason this goes through `Solution::scopeFilter()`: a model
    // types the accents it feels like typing.
    $result = tool('search_solutions', ['query' => 'gestao de operacoes']);

    expect($result['data']['total'])->toBe(1)
        ->and($result['data']['solutions'][0]['name'])->toBe('Gestão de Operações');
});

it('says how many results it withheld', function () {
    Solution::factory()->count(5)->create();

    $result = tool('search_solutions', ['limit' => 2]);

    // Without this a model reports "existem 2 soluções".
    expect($result['data'])->toMatchArray(['total' => 5, 'returned' => 2, 'truncated' => true]);
});

it('accepts an attribute filter by its value or by its label', function () {
    AttributeOption::create(['group' => 'category', 'value' => 'erp', 'label' => 'ERP']);
    AttributeOption::create(['group' => 'category', 'value' => 'iam', 'label' => 'Identidade e acesso']);

    Solution::factory()->create(['name' => 'SAP', 'category' => 'erp']);
    Solution::factory()->create(['name' => 'Okta', 'category' => 'iam']);

    foreach (['erp', 'ERP', 'identidade e acesso'] as $given) {
        $result = tool('search_solutions', ['category' => $given]);
        expect($result['isError'])->toBeFalse();
    }

    expect(tool('search_solutions', ['category' => 'Identidade e acesso'])['data']['solutions'][0]['name'])
        ->toBe('Okta');
});

it('refuses an unknown filter instead of answering with the whole catalog', function () {
    AttributeOption::create(['group' => 'category', 'value' => 'erp', 'label' => 'ERP']);
    Solution::factory()->count(3)->create();

    $result = tool('search_solutions', ['category' => 'nao-existe']);

    // A dropped filter answers with everything and the model reports it as the
    // filtered result — the one failure here that is confidently wrong rather
    // than merely empty.
    expect($result['isError'])->toBeTrue()
        ->and($result['text'])->toContain('ERP');
});

it('opens a solution by slug and refuses an invented one', function () {
    $vendor = Company::factory()->create(['name' => 'SAP SE']);
    $solution = Solution::factory()->create(['name' => 'SAP S/4HANA', 'slug' => 'sap-s4hana', 'vendor_company_id' => $vendor->id]);
    $person = Person::factory()->create(['name' => 'Ana Lima']);
    $solution->people()->attach($person->id, ['role' => 'technical', 'is_primary' => true]);

    $result = tool('get_solution', ['slug' => 'sap-s4hana']);

    expect($result['data']['name'])->toBe('SAP S/4HANA')
        ->and($result['data']['vendor']['name'])->toBe('SAP SE')
        ->and($result['data']['people'][0]['name'])->toBe('Ana Lima')
        // Labelled, not the raw `technical` the pivot stores.
        ->and($result['data']['people'][0]['role'])->toBe(PersonSolutionRole::Technical->label());

    $missing = tool('get_solution', ['slug' => 'nao-existe']);
    expect($missing['isError'])->toBeTrue()
        ->and($missing['text'])->toContain('search_solutions');
});

it('never exposes a numeric id, only slugs', function () {
    $solution = Solution::factory()->create(['slug' => 'alvo']);

    // An id a model quotes back is an id it cannot do anything with.
    expect(tool('get_solution', ['slug' => 'alvo'])['data'])->not->toHaveKey('id')
        ->and(tool('search_solutions')['data']['solutions'][0])->not->toHaveKey('id');
});

/* ------------------------------------------------------------------ */
/*  Diagrams */
/* ------------------------------------------------------------------ */

it('returns a diagram as both a graph and a sentence', function () {
    $a = Solution::factory()->create(['name' => 'CWS', 'slug' => 'cws']);
    $b = Solution::factory()->create(['name' => 'SAP', 'slug' => 'sap']);

    $diagram = Diagram::factory()->create(['name' => 'CWS -> SAP', 'slug' => 'cws-sap']);
    $diagram->chain = [
        'nodes' => [
            ['solution_id' => $a->id, 'label' => null, 'kind' => 'system'],
            ['solution_id' => null, 'label' => 'Aprovado?', 'kind' => 'decision'],
            ['solution_id' => $b->id, 'label' => null, 'kind' => 'system'],
        ],
        'edges' => [
            ['from' => 0, 'to' => 1, 'arrow' => '->', 'protocol' => 'rest'],
            ['from' => 1, 'to' => 2, 'arrow' => '->', 'protocol' => null],
        ],
    ];
    $diagram->save();
    $diagram->afterChainMutation();

    $data = tool('get_diagram', ['slug' => 'cws-sap'])['data'];

    expect($data['nodes'])->toHaveCount(3)
        // A decision block is free text and can never name a Solution.
        ->and($data['nodes'][1])->toMatchArray(['kind' => 'decision', 'label' => 'Aprovado?'])
        ->and($data['nodes'][1])->not->toHaveKey('solution')
        ->and($data['nodes'][0]['solution'])->toBe('cws')
        // `from`/`to` stay indices — rewriting them into labels is lossy the
        // moment two blocks share a name.
        ->and($data['edges'][0])->toMatchArray(['from' => 0, 'to' => 1])
        ->and($data['topology'])->toContain('CWS')
        ->and($data['participants'])->toHaveCount(2);
});

it('lists the diagrams a solution takes part in', function () {
    $solution = Solution::factory()->create(['slug' => 'alvo']);
    $other = Solution::factory()->create();

    $mine = Diagram::factory()->create(['name' => 'Com alvo']);
    attachParticipants($mine, [[$solution, 0], [$other, 1]]);
    $theirs = Diagram::factory()->create(['name' => 'Sem alvo']);
    attachParticipants($theirs, [[$other, 0]]);

    $data = tool('search_diagrams', ['solution' => 'alvo'])['data'];

    expect($data['total'])->toBe(1)
        ->and($data['diagrams'][0]['name'])->toBe('Com alvo');
});

/* ------------------------------------------------------------------ */
/*  Documentation — publication is the whole authorisation */
/* ------------------------------------------------------------------ */

it('shows only published cadernos, and says so when there are none', function () {
    Notebook::factory()->create(['name' => 'Interno', 'slug' => 'interno']);

    $result = tool('list_notebooks');

    // An empty list reads to a model as "não há documentação", which is false
    // about an app holding the pages.
    expect($result['data']['notebooks'])->toBe([])
        ->and($result['data']['note'])->toContain('publicado');

    $published = Notebook::factory()->create(['name' => 'Público', 'slug' => 'publico']);
    $published->forceFill(['published_at' => now()])->save();

    $after = tool('list_notebooks')['data'];
    expect($after['notebooks'])->toHaveCount(1)
        ->and($after['notebooks'][0]['slug'])->toBe('publico');
});

it('refuses an unpublished caderno on every door, by slug', function () {
    $notebook = Notebook::factory()->create(['slug' => 'interno']);
    DocumentationPage::factory()->for($notebook)->create(['slug' => 'pagina', 'documentation' => 'segredo']);

    foreach ([
        ['get_notebook', ['notebook' => 'interno']],
        ['get_documentation_page', ['notebook' => 'interno', 'page' => 'pagina']],
        ['search_documentation', ['query'      => 'segredo', 'notebook' => 'interno']],
    ] as [$name, $arguments]) {
        $result = tool($name, $arguments);
        expect($result['isError'])->toBeTrue()
            ->and($result['text'])->not->toContain('segredo');
    }
});

it('never says whether an unpublished caderno exists', function () {
    Notebook::factory()->create(['slug' => 'projeto-secreto']);

    // One message for "não existe" and for "existe mas não foi publicado":
    // confirming the second enumerates what this company runs, from a
    // credential that was never meant to.
    $real = tool('get_notebook', ['notebook' => 'projeto-secreto'])['text'];
    $fake = tool('get_notebook', ['notebook' => 'nao-existe-mesmo'])['text'];

    expect(str_replace('projeto-secreto', 'X', $real))->toBe(str_replace('nao-existe-mesmo', 'X', $fake));
});

it('walks the page tree in reading order with each page depth', function () {
    $notebook = Notebook::factory()->create(['slug' => 'manual']);
    $notebook->forceFill(['published_at' => now()])->save();

    $root = DocumentationPage::factory()->for($notebook)->create(['title' => 'Raiz', 'position' => 0, 'documentation' => 'x']);
    $child = DocumentationPage::factory()->for($notebook)->create(['title' => 'Filha', 'position' => 0, 'documentation' => 'y']);
    $child->parent()->associate($root)->save();
    $second = DocumentationPage::factory()->for($notebook)->create(['title' => 'Segunda', 'position' => 1, 'documentation' => 'z']);

    $pages = tool('get_notebook', ['notebook' => 'manual'])['data']['pages'];

    // A flat `orderBy('position')` would interleave depths and put "Segunda"
    // between the root and its own child.
    expect(array_column($pages, 'title'))->toBe(['Raiz', 'Filha', 'Segunda'])
        ->and(array_column($pages, 'depth'))->toBe([0, 1, 0]);
});

it('returns a page as Markdown, in a block of its own', function () {
    publishedPage("# Título\n\nCorpo com {% hint style=\"info\" %}aviso{% endhint %}.", 'Arquitetura');

    $page = DocumentationPage::where('title', 'Arquitetura')->firstOrFail();
    $result = tool('get_documentation_page', ['notebook' => 'manual', 'page' => $page->slug]);

    expect($result['content'])->toHaveCount(2)
        ->and($result['content'][1]['text'])->toContain('# Título')
        // The dialect survives: a {% diagram %} is a slug `get_diagram` takes,
        // and a {% hint %} still reads as a warning.
        ->and($result['content'][1]['text'])->toContain('{% hint');
});

it('MASKS every protected value, on every surface that can reach one', function () {
    $markdown = "Header: `{% secret %}Bearer super-secreto-123{% endsecret %}`\n\nSenha: {% secret %}p4ssw0rd{% endsecret %}";
    publishedPage($markdown, 'Credenciais');

    $page = DocumentationPage::where('title', 'Credenciais')->firstOrFail();
    $result = tool('get_documentation_page', ['notebook' => 'manual', 'page' => $page->slug]);

    expect($result['text'])->not->toContain('super-secreto-123')
        ->and($result['text'])->not->toContain('p4ssw0rd')
        // What it DOES see: that a protected value is there, and which one.
        ->and($result['text'])->toContain('[[SECRET-1]]')
        ->and($result['text'])->toContain('[[SECRET-2]]');

    // The search index is built from the RENDERED html, which carries locks —
    // so there was never a path from here to a value. Asserted anyway: this is
    // the invariant, not the implementation.
    $search = tool('search_documentation', ['query' => 'senha']);
    expect($search['text'])->not->toContain('super-secreto-123')
        ->and($search['text'])->not->toContain('p4ssw0rd');
});

it('has no tool that reveals a secret', function () {
    $names = collect(ToolRegistry::TOOLS)->map(fn (string $tool) => app($tool)->name());

    // The plaintext has exactly one door (`RevealPageSecret`), throttled per
    // reader. A second door reached by a static token would undo all of it.
    expect($names->filter(fn (string $name) => str_contains($name, 'secret')))->toBeEmpty();
});

it('searches sections rather than pages, and names what it searched', function () {
    publishedPage("Introdução ao tema.\n\n## Autenticação\n\nUsa OAuth com refresh token.", 'Guia');

    $data = tool('search_documentation', ['query' => 'oauth'])['data'];

    expect($data['total'])->toBeGreaterThan(0)
        ->and($data['searched_notebooks'])->toBe(['manual'])
        // A partial answer would say so; a complete one must not.
        ->and($data)->not->toHaveKey('not_searched_notebooks')
        ->and($data['results'][0]['section'])->toContain('Autenticação')
        ->and($data['results'][0]['snippet'])->toContain('OAuth');
});

/* ------------------------------------------------------------------ */
/*  People and companies */
/* ------------------------------------------------------------------ */

it('finds a person by an address that lives only on a contact row', function () {
    $person = Person::factory()->create(['name' => 'Bruno Alves', 'email' => null]);
    $person->contacts()->create(['type' => 'email', 'value' => 'bruno@fornecedor.com', 'is_primary' => true]);

    // Folding is only half of "can this be found"; the other half is which
    // columns the `orWhere` chain names.
    $data = tool('search_people', ['query' => 'bruno@fornecedor.com'])['data'];

    expect($data['total'])->toBe(1)
        ->and($data['people'][0]['name'])->toBe('Bruno Alves');
});

it('never reports a person account or role', function () {
    $person = Person::factory()->create(['name' => 'Carla Souza', 'slug' => 'carla-souza']);
    $account = User::factory()->create([
        'email' => 'carla.souza@leomadeiras.com.br',
        'role'  => UserRole::Admin->value,
    ]);
    $person->user()->associate($account)->save();

    $result = tool('get_person', ['slug' => 'carla-souza']);

    // A read credential that could enumerate who holds an admin account would
    // be a reconnaissance tool. Nothing about the AUTHENTICATION side of a
    // person reaches this payload: not the role, not the account's address,
    // not even that one exists.
    expect($result['text'])->not->toContain(UserRole::Admin->value)
        ->and($result['text'])->not->toContain('carla.souza@leomadeiras.com.br')
        ->and($result['data'])->not->toHaveKey('account')
        ->and($result['data'])->not->toHaveKey('role')
        ->and($result['data'])->not->toHaveKey('user_id')
        ->and($result['data']['name'])->toBe('Carla Souza');
});

it('opens a company with its contacts and what it supplies', function () {
    $company = Company::factory()->create(['name' => 'Fornecedor X', 'slug' => 'fornecedor-x']);
    Person::factory()->create(['name' => 'Contato X', 'company_id' => $company->id]);
    Solution::factory()->create(['name' => 'Produto X', 'vendor_company_id' => $company->id]);

    $data = tool('get_company', ['slug' => 'fornecedor-x'])['data'];

    expect($data['people'][0]['name'])->toBe('Contato X')
        ->and($data['provided_solutions'][0]['name'])->toBe('Produto X');
});

/* ------------------------------------------------------------------ */
/*  Shape */
/* ------------------------------------------------------------------ */

it('drops blank fields rather than reporting them as facts', function () {
    Solution::factory()->create(['name' => 'Minimal', 'slug' => 'minimal', 'cloud' => null, 'description' => null]);

    $data = tool('get_solution', ['slug' => 'minimal'])['data'];

    // `"cloud": null` reads to a model as "this solution HAS no cloud" when it
    // means "ninguém preencheu".
    expect($data)->not->toHaveKey('cloud')
        ->and($data)->not->toHaveKey('description')
        ->and($data['name'])->toBe('Minimal');
});

it('answers a missing required argument as a protocol error', function () {
    // The client had the schema and ignored it — retrying would fail
    // identically, so it is not something the model can recover from.
    test()->postJson(
        route('mcp.handle'),
        ['jsonrpc'       => '2.0', 'id' => 1, 'method' => 'tools/call', 'params' => ['name' => 'get_solution', 'arguments' => []]],
        ['Authorization' => 'Bearer ' . McpToken::mint('Test')['plain']],
    )->assertOk()->assertJsonPath('error.code', -32602);
});
