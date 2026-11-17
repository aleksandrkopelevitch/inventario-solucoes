<?php

use App\Enums\AccessModule;
use App\Enums\PersonSolutionRole;
use App\Mcp\McpScopeRepository;
use App\Mcp\OAuth;
use App\Mcp\ToolRegistry;
use App\Mcp\Tools\Write\WriteTool;
use App\Mcp\Writes;
use App\Models\Company;
use App\Models\McpToken;
use App\Models\McpWrite;
use App\Models\Person;
use App\Models\Solution;
use App\Models\User;
use Database\Seeders\AttributeOptionSeeder;
use Illuminate\Foundation\Testing\LazilyRefreshDatabase;
use Illuminate\Support\Facades\Gate;
use Laravel\Passport\Bridge\Client as BridgeClient;
use Laravel\Passport\Bridge\Scope;
use Laravel\Passport\Passport;

uses(LazilyRefreshDatabase::class);

beforeEach(function () {
    $this->seed(AttributeOptionSeeder::class);
});

/** An Editor of the catalog, connected with writing authorized. */
function catalogWriter(): User
{
    return User::factory()->editor(AccessModule::Catalog)->create();
}

/** One JSON-RPC message as `$user`, holding `$scopes`. */
function mcpWriteAs(User $user, string $method, array $params = [], array $scopes = [OAuth::SCOPE, OAuth::WRITE_SCOPE])
{
    Passport::actingAs($user, $scopes);

    return test()->postJson(route('mcp.handle'), ['jsonrpc' => '2.0', 'id' => 1, 'method' => $method, 'params' => $params]);
}

/** One `tools/call`, decoded. @return array{isError: bool, text: string, data: ?array} */
function writeTool(User $user, string $name, array $arguments = [], array $scopes = [OAuth::SCOPE, OAuth::WRITE_SCOPE]): array
{
    $result = mcpWriteAs($user, 'tools/call', ['name' => $name, 'arguments' => $arguments], $scopes)
        ->assertOk()
        ->json('result');

    return [
        'isError' => $result['isError'],
        'text'    => $result['content'][0]['text'],
        'data'    => json_decode($result['content'][0]['text'], true),
    ];
}

/** @return list<string> */
function writeToolNames(): array
{
    return collect(app(ToolRegistry::class)->every())
        ->filter(fn ($tool) => $tool instanceof Writes)
        ->map(fn ($tool) => $tool->name())
        ->values()
        ->all();
}

/* ------------------------------------------------------------------ */
/*  Who sees the write tools */
/* ------------------------------------------------------------------ */

it('never shows a write tool to a minted token', function () {
    $tools = collect(test()->postJson(route('mcp.handle'), ['jsonrpc' => '2.0', 'id' => 1, 'method' => 'tools/list'], [
        'Authorization' => 'Bearer ' . McpToken::mint('Script')['plain'],
    ])->assertOk()->json('result.tools'))->pluck('name');

    expect($tools->intersect(writeToolNames()))->toBeEmpty();
});

it('shows a catalog Reader no write tool, even with the scope', function () {
    $tools = collect(mcpWriteAs(User::factory()->reader()->create(), 'tools/list')->json('result.tools'))->pluck('name');

    expect($tools->intersect(writeToolNames()))->toBeEmpty();
});

it('shows an Editor without mcp:write no write tool, and tells it how to enable them', function () {
    $editor = catalogWriter();

    $tools = collect(mcpWriteAs($editor, 'tools/list', scopes: [OAuth::SCOPE])->json('result.tools'))->pluck('name');
    expect($tools->intersect(writeToolNames()))->toBeEmpty();

    // Hidden is UNKNOWN when called — the same sentence a typo gets.
    mcpWriteAs($editor, 'tools/call', ['name' => 'create_solution', 'arguments' => ['name' => 'X']], [OAuth::SCOPE])
        ->assertJsonPath('error.code', -32602);

    expect(mcpWriteAs($editor, 'initialize', scopes: [OAuth::SCOPE])->json('result.instructions'))
        ->toContain('reconecte o conector e autorize a edição');
});

it('shows a granted Editor the catalog write tools, annotated as writes', function () {
    $tools = collect(mcpWriteAs(catalogWriter(), 'tools/list')->json('result.tools'))->keyBy('name');

    expect($tools->keys())->toContain('create_solution', 'update_solution', 'link_person_to_solution', 'create_person', 'update_company');

    expect($tools['create_solution']['annotations'])->toMatchArray(['readOnlyHint' => false, 'destructiveHint' => false, 'idempotentHint' => false])
        ->and($tools['update_solution']['annotations'])->toMatchArray(['readOnlyHint' => false, 'destructiveHint' => true])
        ->and($tools['unlink_person_from_solution']['annotations']['destructiveHint'])->toBeTrue()
        ->and($tools['search_solutions']['annotations']['readOnlyHint'])->toBeTrue();

    expect(mcpWriteAs(catalogWriter(), 'initialize')->json('result.instructions'))
        ->toContain('CRIAR e EDITAR')
        ->toContain('confirme com a pessoa');
});

it('declares a module on every write tool', function () {
    foreach (app(ToolRegistry::class)->every() as $tool) {
        if ($tool instanceof Writes) {
            expect($tool->module())->not->toBeNull();
        }
    }
});

/* ------------------------------------------------------------------ */
/*  The scope itself */
/* ------------------------------------------------------------------ */

it('strips mcp:write from an account that is an Editor of nothing writable', function () {
    $repository = app(McpScopeRepository::class);
    $client = new BridgeClient('client-id', 'Claude', ['https://claude.ai/cb']);
    $scopes = [new Scope(OAuth::SCOPE), new Scope(OAuth::WRITE_SCOPE)];

    $reader = User::factory()->reader()->create();
    $editor = catalogWriter();

    $ids = fn (array $finalized) => array_map(fn ($scope) => $scope->getIdentifier(), $finalized);

    expect($ids($repository->finalizeScopes($scopes, 'authorization_code', $client, (string) $reader->id)))->toBe([OAuth::SCOPE])
        ->and($ids($repository->finalizeScopes($scopes, 'authorization_code', $client, (string) $editor->id)))->toBe([OAuth::SCOPE, OAuth::WRITE_SCOPE]);
});

it('names the writes on the consent screen only for an account that may hold them', function () {
    config(['mcp.redirect_origins' => ['https://claude.ai']]);

    $clientId = test()->postJson(route('mcp.oauth.register'), [
        'client_name'   => 'Claude',
        'redirect_uris' => ['https://claude.ai/cb'],
    ])->json('client_id');

    $consent = fn (User $user) => test()->actingAs($user, 'web')->get(route('passport.authorizations.authorize', [
        'client_id'             => $clientId,
        'redirect_uri'          => 'https://claude.ai/cb',
        'response_type'         => 'code',
        'scope'                 => implode(' ', OAuth::SCOPES),
        'state'                 => 'xyz',
        'code_challenge'        => str_repeat('a', 43),
        'code_challenge_method' => 'S256',
    ]));

    $consent(catalogWriter())->assertOk()->assertSee('O que essa conexão vai poder alterar')->assertSee('Soluções, Pessoas e Empresas');
    $consent(User::factory()->reader()->create())->assertOk()->assertDontSee('O que essa conexão vai poder alterar')->assertSee('Somente leitura');
});

/* ------------------------------------------------------------------ */
/*  create_solution */
/* ------------------------------------------------------------------ */

it('creates a solution from labels and slugs, and logs it', function () {
    $vendor = Company::factory()->create(['slug' => 'totvs']);
    $writer = catalogWriter();

    $result = writeTool($writer, 'create_solution', [
        'name'     => 'Protheus',
        'category' => 'ERP',           // a label, as every payload prints it
        'status'   => 'active',
        'vendor'   => 'totvs',
        'cloud'    => null,            // null means "not sent"
    ]);

    expect($result['isError'])->toBeFalse()
        ->and($result['data']['solution'])->toMatchArray(['slug' => 'protheus', 'category' => 'ERP', 'status' => 'Ativo']);

    $solution = Solution::where('slug', 'protheus')->firstOrFail();
    expect($solution->vendor_company_id)->toBe($vendor->id)
        ->and($solution->support_type)->toBe('third_party');   // SaveSolution's default, shared with the panel

    $write = McpWrite::sole();
    expect($write->tool)->toBe('create_solution')
        ->and($write->before)->toBeNull()
        ->and($write->after)->toMatchArray(['name' => 'Protheus', 'category' => 'erp', 'slug' => 'protheus'])
        ->and($write->user_id)->toBe($writer->id);
});

it('refuses an unknown attribute value or slug instead of dropping it', function () {
    $result = writeTool(catalogWriter(), 'create_solution', [
        'name' => 'X', 'category' => 'banana', 'status' => 'active', 'vendor' => 'ninguem',
    ]);

    expect($result['isError'])->toBeTrue()
        ->and($result['text'])->toContain('category: "banana" não é um valor conhecido')
        ->and($result['text'])->toContain('nenhuma empresa com o slug "ninguem"');

    expect(Solution::count())->toBe(0)->and(McpWrite::count())->toBe(0);
});

it('refuses with the app own validation, naming the argument', function () {
    $result = writeTool(catalogWriter(), 'create_solution', ['name' => 'X', 'category' => 'erp', 'slug' => 'Soluções']);

    expect($result['isError'])->toBeTrue()
        ->and($result['text'])->toContain('status')
        ->and($result['text'])->toContain('slug');
});

it('refuses a duplicate name made by somebody else, and creates it with allow_duplicate', function () {
    Solution::factory()->create(['name' => 'Gestão de Operações', 'slug' => 'gestao']);

    $refused = writeTool(catalogWriter(), 'create_solution', ['name' => 'gestao de operacoes', 'category' => 'erp', 'status' => 'active']);

    expect($refused['isError'])->toBeTrue()->and($refused['text'])->toContain('slug "gestao"');

    $forced = writeTool(catalogWriter(), 'create_solution', [
        'name' => 'Gestão de Operações', 'category' => 'erp', 'status' => 'active', 'allow_duplicate' => true,
    ]);

    expect($forced['isError'])->toBeFalse()->and(Solution::count())->toBe(2);
});

it('answers a retried create with the first record, and names what the retry did not apply', function () {
    $writer = catalogWriter();

    writeTool($writer, 'create_solution', ['name' => 'Vtex', 'category' => 'ecommerce', 'status' => 'active']);
    $retry = writeTool($writer, 'create_solution', ['name' => 'VTEX', 'category' => 'ecommerce', 'status' => 'active', 'description' => 'Loja']);

    expect($retry['isError'])->toBeFalse()
        ->and($retry['data']['message'])->toContain('nada novo foi criado')
        ->and($retry['data']['message'])->toContain('NÃO foram aplicados: description')
        ->and(Solution::count())->toBe(1)
        ->and(McpWrite::count())->toBe(1);
});

it('does not treat an old create as a retry', function () {
    $writer = catalogWriter();
    writeTool($writer, 'create_solution', ['name' => 'Vtex', 'category' => 'ecommerce', 'status' => 'active']);

    $this->travel(WriteTool::RETRY_WINDOW_MINUTES + 1)->minutes();

    expect(writeTool($writer, 'create_solution', ['name' => 'Vtex', 'category' => 'ecommerce', 'status' => 'active'])['isError'])->toBeTrue();
});

/* ------------------------------------------------------------------ */
/*  update_solution — a patch */
/* ------------------------------------------------------------------ */

it('patches only what was sent: absent and null keep, clear empties', function () {
    $solution = Solution::factory()->create(['slug' => 'sap', 'description' => 'ERP central', 'cloud' => 'azure', 'criticality' => 'high']);

    $result = writeTool(catalogWriter(), 'update_solution', [
        'slug'        => 'sap',
        'status'      => 'Descontinuado',
        'description' => null,           // a model's "I am not setting this"
        'clear'       => ['cloud'],
    ]);

    expect($result['isError'])->toBeFalse();

    $solution->refresh();
    expect($solution->status)->toBe('deprecated')
        ->and($solution->description)->toBe('ERP central')
        ->and($solution->criticality)->toBe('high')
        ->and($solution->cloud)->toBeNull();

    expect(McpWrite::sole()->before)->toBe(['status' => 'active', 'cloud' => 'azure'])
        ->and(McpWrite::sole()->after)->toBe(['status' => 'deprecated', 'cloud' => null]);
});

it('refuses clearing a required field, and changes nothing', function () {
    Solution::factory()->create(['slug' => 'sap']);

    $result = writeTool(catalogWriter(), 'update_solution', ['slug' => 'sap', 'clear' => ['status']]);

    expect($result['isError'])->toBeTrue()->and(McpWrite::count())->toBe(0);
});

it('logs nothing when nothing changed', function () {
    Solution::factory()->create(['slug' => 'sap', 'status' => 'active']);

    $result = writeTool(catalogWriter(), 'update_solution', ['slug' => 'sap', 'status' => 'Ativo']);

    expect($result['data']['message'])->toContain('Nada mudou')->and(McpWrite::count())->toBe(0);
});

it('asks the policy, not only the registry', function () {
    Solution::factory()->create(['slug' => 'sap']);
    Gate::before(fn ($user, string $ability) => $ability === 'update' ? false : null);

    $result = writeTool(catalogWriter(), 'update_solution', ['slug' => 'sap', 'status' => 'planned']);

    expect($result['isError'])->toBeTrue()->and($result['text'])->toContain('não tem permissão');
});

it('refuses the same invalid value through the panel and through the tool', function () {
    $person = Person::factory()->create(['slug' => 'ana']);
    $writer = catalogWriter();

    $this->actingAs($writer, 'web')
        ->patchJson(route('people.update', $person), ['name' => 'Ana', 'email' => 'nao-e-email'])
        ->assertStatus(422);

    $tool = writeTool($writer, 'update_person', ['slug' => 'ana', 'email' => 'nao-e-email']);

    expect($tool['isError'])->toBeTrue()->and($tool['text'])->toContain('email');
});

/* ------------------------------------------------------------------ */
/*  People, companies, links */
/* ------------------------------------------------------------------ */

it('creates and updates a person and a company', function () {
    $writer = catalogWriter();

    expect(writeTool($writer, 'create_company', ['name' => 'Totvs', 'kind' => 'Fornecedor'])['isError'])->toBeFalse();
    expect(writeTool($writer, 'create_person', ['name' => 'Ana Lima', 'company' => 'totvs', 'email' => 'ana@totvs.com'])['isError'])->toBeFalse();

    $person = Person::where('slug', 'ana-lima')->firstOrFail();
    expect($person->company?->slug)->toBe('totvs');

    expect(writeTool($writer, 'update_person', ['slug' => 'ana-lima', 'job_title' => 'Gerente', 'clear' => ['company']])['isError'])->toBeFalse();
    expect($person->refresh())->job_title->toBe('Gerente')->company_id->toBeNull();

    expect(writeTool($writer, 'update_company', ['slug' => 'totvs', 'website' => 'https://totvs.com'])['isError'])->toBeFalse();
    expect(Company::where('slug', 'totvs')->value('website'))->toBe('https://totvs.com');
});

it('links, re-roles and unlinks, logging the whole set on the solution', function () {
    $solution = Solution::factory()->create(['slug' => 'sap']);
    Person::factory()->create(['slug' => 'ana', 'name' => 'Ana']);
    $writer = catalogWriter();

    writeTool($writer, 'link_person_to_solution', ['solution' => 'sap', 'person' => 'ana', 'role' => 'technical']);
    writeTool($writer, 'link_person_to_solution', ['solution' => 'sap', 'person' => 'ana', 'role' => 'Gestor']);
    $same = writeTool($writer, 'link_person_to_solution', ['solution' => 'sap', 'person' => 'ana', 'role' => 'manager']);

    expect($same['data']['message'])->toContain('nada mudou')
        ->and($solution->people()->count())->toBe(1)
        ->and($solution->people()->first()->pivot->role)->toBe(PersonSolutionRole::Manager->value);

    writeTool($writer, 'unlink_person_from_solution', ['solution' => 'sap', 'person' => 'ana']);

    expect($solution->people()->count())->toBe(0);

    $log = McpWrite::orderBy('id')->get();
    expect($log)->toHaveCount(3)
        ->and($log[0]->subject_type)->toBe(Solution::class)
        ->and($log[0]->before)->toBe(['people' => []])
        ->and($log[1]->after)->toBe(['people' => [['person' => 'ana', 'role' => 'manager', 'is_primary' => false]]])
        ->and($log[2]->after)->toBe(['people' => []]);
});

it('rate-limits writes per person with a readable result', function () {
    $this->freezeTime();
    Solution::factory()->create(['slug' => 'sap']);
    $writer = catalogWriter();

    foreach (range(1, WriteTool::PER_MINUTE) as $i) {
        writeTool($writer, 'update_solution', ['slug' => 'sap', 'description' => "v{$i}"]);
    }

    $limited = writeTool($writer, 'update_solution', ['slug' => 'sap', 'description' => 'over']);

    expect($limited['isError'])->toBeTrue()->and($limited['text'])->toContain('Muitas alterações');
});

/* ------------------------------------------------------------------ */
/*  The write log and its revert */
/* ------------------------------------------------------------------ */

it('stores the log encrypted', function () {
    Solution::factory()->create(['slug' => 'sap']);
    writeTool(catalogWriter(), 'update_solution', ['slug' => 'sap', 'description' => 'segredo-visivel']);

    expect(DB::table('mcp_writes')->value('after'))->not->toContain('segredo-visivel');
});

it('reverts an update, and skips one changed since', function () {
    $solution = Solution::factory()->create(['slug' => 'sap', 'status' => 'active']);
    $admin = User::factory()->admin()->create();

    writeTool(catalogWriter(), 'update_solution', ['slug' => 'sap', 'status' => 'planned']);
    $write = McpWrite::sole();

    $this->actingAs($admin, 'web')
        ->postJson(route('mcp-writes.revert', $write))
        ->assertOk()
        ->assertJsonPath('type', 'success');

    expect($solution->refresh()->status)->toBe('active')
        ->and($write->refresh()->reverted_by_id)->toBe($admin->id);

    // A second write, then somebody edits the field in the app: the revert
    // must not undo that person's edit.
    writeTool(catalogWriter(), 'update_solution', ['slug' => 'sap', 'status' => 'planned']);
    $solution->refresh()->update(['status' => 'deprecated']);

    $this->actingAs($admin, 'web')
        ->postJson(route('mcp-writes.revert', McpWrite::latest('id')->first()))
        ->assertJsonPath('type', 'warning')
        ->assertJsonPath('message', 'Não revertida: o registro foi alterado depois desta mudança.');

    expect($solution->refresh()->status)->toBe('deprecated');
});

it('reverts a create by deleting the bare record, and refuses once it has links', function () {
    $admin = User::factory()->admin()->create();
    $writer = catalogWriter();

    writeTool($writer, 'create_solution', ['name' => 'Bare', 'category' => 'erp', 'status' => 'active']);
    writeTool($writer, 'create_solution', ['name' => 'Linked', 'category' => 'erp', 'status' => 'active']);
    Solution::where('slug', 'linked')->first()->people()->attach(Person::factory()->create(), ['role' => 'technical']);

    [$bare, $linked] = McpWrite::orderBy('id')->get();

    $this->actingAs($admin, 'web')->postJson(route('mcp-writes.revert', $bare))->assertJsonPath('type', 'success');
    $this->actingAs($admin, 'web')->postJson(route('mcp-writes.revert', $linked))->assertJsonPath('type', 'warning');

    expect(Solution::where('slug', 'bare')->exists())->toBeFalse()
        ->and(Solution::where('slug', 'linked')->exists())->toBeTrue();
});

it('reverts a selection newest first, so a field written twice ends at its oldest value', function () {
    $solution = Solution::factory()->create(['slug' => 'sap', 'status' => 'active']);
    $writer = catalogWriter();

    writeTool($writer, 'update_solution', ['slug' => 'sap', 'status' => 'planned']);
    writeTool($writer, 'update_solution', ['slug' => 'sap', 'status' => 'deprecated']);

    $this->actingAs(User::factory()->admin()->create(), 'web')
        ->postJson(route('mcp-writes.revert-selection', ['filter' => ['user' => $writer->id]]))
        ->assertOk()
        ->assertJsonPath('message', '2 alterações revertidas.');

    expect($solution->refresh()->status)->toBe('active');
});

it('refuses an unfiltered bulk revert', function () {
    $this->actingAs(User::factory()->admin()->create(), 'web')
        ->postJson(route('mcp-writes.revert-selection'))
        ->assertStatus(422);
});

it('keeps the screen and the reverts for admins', function () {
    Solution::factory()->create(['slug' => 'sap']);
    writeTool(catalogWriter(), 'update_solution', ['slug' => 'sap', 'description' => 'Nova']);
    $write = McpWrite::sole();

    $editor = catalogWriter();
    $this->actingAs($editor, 'web')->get(route('mcp-writes.index'))->assertForbidden();
    $this->actingAs($editor, 'web')->postJson(route('mcp-writes.revert', $write))->assertForbidden();

    $this->actingAs(User::factory()->admin()->create(), 'web')
        ->get(route('mcp-writes.index'))
        ->assertOk()
        ->assertSee('Editar uma solução')
        ->assertSee('Nova');
});

it('prunes rows past the retention', function () {
    Solution::factory()->create(['slug' => 'sap']);
    writeTool(catalogWriter(), 'update_solution', ['slug' => 'sap', 'description' => 'x']);

    $this->travel(McpWrite::RETENTION_DAYS + 1)->days();
    $this->artisan('model:prune', ['--model' => [McpWrite::class]])->assertSuccessful();

    expect(McpWrite::count())->toBe(0);
});

/* ------------------------------------------------------------------ */
/*  Findings of the phase-1 review */
/* ------------------------------------------------------------------ */

it('recognises a retried confirmed duplicate by the write log, not by the first record with the name', function () {
    Solution::factory()->create(['name' => 'Vtex', 'slug' => 'vtex-original']);
    $writer = catalogWriter();
    $args = ['name' => 'Vtex', 'category' => 'ecommerce', 'status' => 'active', 'allow_duplicate' => true];

    writeTool($writer, 'create_solution', $args);
    $retry = writeTool($writer, 'create_solution', $args);

    expect($retry['data']['message'])->toContain('nada novo foi criado')
        ->and(Solution::count())->toBe(2);
});

it('refuses to clear a column the save would silently refill', function () {
    Solution::factory()->create(['slug' => 'sap', 'support_type' => 'internal']);

    $result = writeTool(catalogWriter(), 'update_solution', ['slug' => 'sap', 'clear' => ['support_type']]);

    expect($result['isError'])->toBeTrue()->and($result['text'])->toContain('não pode ficar vazio');
});

it('keeps is_primary when an unlink is reverted', function () {
    $solution = Solution::factory()->create(['slug' => 'sap']);
    $ana = Person::factory()->create(['slug' => 'ana']);
    $solution->people()->attach($ana, ['role' => 'technical', 'is_primary' => true]);

    writeTool(catalogWriter(), 'unlink_person_from_solution', ['solution' => 'sap', 'person' => 'ana']);

    $this->actingAs(User::factory()->admin()->create(), 'web')
        ->postJson(route('mcp-writes.revert', McpWrite::sole()))
        ->assertJsonPath('type', 'success');

    expect((bool) $solution->people()->first()->pivot->is_primary)->toBeTrue();
});

it('skips a revert whose old value is no longer valid instead of failing', function () {
    $old = Company::factory()->create(['slug' => 'old']);
    Company::factory()->create(['slug' => 'new']);
    $solution = Solution::factory()->create(['slug' => 'sap', 'vendor_company_id' => $old->id]);

    writeTool(catalogWriter(), 'update_solution', ['slug' => 'sap', 'vendor' => 'new']);
    $old->delete();

    $this->actingAs(User::factory()->admin()->create(), 'web')
        ->postJson(route('mcp-writes.revert', McpWrite::sole()))
        ->assertOk()
        ->assertJsonPath('type', 'warning');

    expect($solution->refresh()->vendor?->slug)->toBe('new');
});

it('does not delete a created record that was edited since', function () {
    $writer = catalogWriter();
    writeTool($writer, 'create_solution', ['name' => 'Bare', 'category' => 'erp', 'status' => 'active']);

    $this->travel(1)->minutes();
    Solution::where('slug', 'bare')->first()->update(['description' => 'escrito no app']);

    $this->actingAs(User::factory()->admin()->create(), 'web')
        ->postJson(route('mcp-writes.revert', McpWrite::sole()))
        ->assertJsonPath('type', 'warning');

    expect(Solution::where('slug', 'bare')->exists())->toBeTrue();
});

it('keeps the write history when the account is deleted', function () {
    Solution::factory()->create(['slug' => 'sap']);
    $writer = catalogWriter();
    writeTool($writer, 'update_solution', ['slug' => 'sap', 'description' => 'x']);

    $writer->forceDelete();

    expect(McpWrite::sole()->user_id)->toBeNull();
});
