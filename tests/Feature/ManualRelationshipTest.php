<?php

use App\Actions\Catalog\SaveManualRelationship;
use App\Enums\AccessModule;
use App\Enums\RelationshipDirection;
use App\Mcp\OAuth;
use App\Models\DocumentationPage;
use App\Models\McpWrite;
use App\Models\Solution;
use App\Models\SolutionRelationship;
use App\Models\User;
use App\Services\SolutionRelationshipService;
use Database\Seeders\AttributeOptionSeeder;
use Illuminate\Foundation\Testing\LazilyRefreshDatabase;
use Laravel\Passport\Passport;

uses(LazilyRefreshDatabase::class);

/*
 * Manual interconnections (docs-and-specs/solution-relationships-from-uml.md,
 * phase 2): recorded inline on the solution page or through the MCP, one per
 * pair, overriding what the documentation says about that pair.
 */

function manSolution(string $slug, string $name, string $category = 'erp'): Solution
{
    return Solution::factory()->create(['slug' => $slug, 'name' => $name, 'category' => $category]);
}

function manEditor(): User
{
    return User::factory()->editor(AccessModule::Catalog)->create();
}

/** A page whose UML says `$from` calls `$to`. */
function manDocumented(Solution $from, Solution $to): DocumentationPage
{
    return DocumentationPage::factory()->create(['documentation' => "{% uml type=\"sequence\" title=\"Fluxo\" %}\n" . json_encode([
        'participants' => [['id' => 'a', 'solution' => $from->slug], ['id' => 'b', 'solution' => $to->slug]],
        'messages'     => [['from' => 'a', 'to' => 'b', 'label' => 'REST']],
    ]) . "\n{% enduml %}"]);
}

function manDirection(Solution $from, Solution $other): ?RelationshipDirection
{
    return app(SolutionRelationshipService::class)->forSolution($from)->first(fn ($r) => $r->other->is($other))?->direction;
}

/** One MCP tools/call as a person who may write. @return array{isError: bool, text: string, data: ?array} */
function manTool(User $user, string $name, array $arguments, array $scopes = [OAuth::SCOPE, OAuth::WRITE_SCOPE]): array
{
    Passport::actingAs($user, $scopes);
    $result = test()->postJson(route('mcp.handle'), [
        'jsonrpc' => '2.0', 'id' => 1, 'method' => 'tools/call', 'params' => ['name' => $name, 'arguments' => $arguments],
    ])->assertOk()->json('result');

    return ['isError' => $result['isError'], 'text' => $result['content'][0]['text'], 'data' => json_decode($result['content'][0]['text'], true)];
}

/* ------------------------------------------------------------------ */
/*  On the solution page */
/* ------------------------------------------------------------------ */

it('records an interconnection from either side as one normalized pair, answering with the card', function () {
    $sap = manSolution('sap', 'SAP');
    $vtex = manSolution('vtex', 'VTEX');

    $response = $this->actingAs(manEditor())
        ->postJson(route('solutions.relationships.store', $vtex), ['other_solution_id' => $sap->id, 'direction' => 'incoming'])
        ->assertOk()
        ->assertJsonPath('message', 'Interconexão salva.')
        ->assertJsonPath('updatableSlots.0.id', 'solution-relationships-slot');

    expect($response->json('updatableSlots.0.content'))->toContain('SAP chama VTEX')
        ->and(SolutionRelationship::sole()->only(['solution_a_id', 'solution_b_id']))->toBe(['solution_a_id' => $sap->id, 'solution_b_id' => $vtex->id])
        ->and(manDirection($sap, $vtex))->toBe(RelationshipDirection::Outgoing)
        ->and(manDirection($vtex, $sap))->toBe(RelationshipDirection::Incoming);

    // The same pair from the other side is a duplicate, named.
    $this->actingAs(manEditor())
        ->postJson(route('solutions.relationships.store', $sap), ['other_solution_id' => $vtex->id, 'direction' => 'both'])
        ->assertUnprocessable()
        ->assertJsonPath('message', fn (string $m) => str_contains($m, 'Já existe uma interconexão entre SAP e VTEX'));

    $this->actingAs(manEditor())
        ->postJson(route('solutions.relationships.store', $sap), ['other_solution_id' => $sap->id, 'direction' => 'both'])
        ->assertUnprocessable()
        ->assertJsonPath('message', fn (string $m) => str_contains($m, 'não se relaciona com ela mesma'));
});

it('corrects a record, re-pointing it at another solution, and removes it', function () {
    $sap = manSolution('sap', 'SAP');
    $vtex = manSolution('vtex', 'VTEX');
    $fresh = manSolution('fresh', 'Freshdesk');
    $editor = manEditor();

    $this->actingAs($editor)->postJson(route('solutions.relationships.store', $sap), ['other_solution_id' => $vtex->id, 'direction' => 'outgoing'])->assertOk();
    $record = SolutionRelationship::sole();

    $this->actingAs($editor)
        ->patchJson(route('solutions.relationships.update', [$sap, $record]), ['other_solution_id' => $fresh->id, 'direction' => 'both'])
        ->assertOk();

    expect(SolutionRelationship::count())->toBe(1)
        ->and(manDirection($sap, $fresh))->toBe(RelationshipDirection::Both)
        ->and(manDirection($sap, $vtex))->toBeNull();

    $this->actingAs($editor)
        ->deleteJson(route('solutions.relationships.destroy', [$sap, $record]))
        ->assertOk()
        ->assertJsonPath('message', 'Interconexão removida.');

    expect(SolutionRelationship::count())->toBe(0);
});

it('refuses a reader, and a record that does not involve the solution in the URL', function () {
    $sap = manSolution('sap', 'SAP');
    $vtex = manSolution('vtex', 'VTEX');
    $other = manSolution('outra', 'Outra');
    $record = app(SaveManualRelationship::class)->handle($sap, $vtex, RelationshipDirection::Outgoing);

    $this->actingAs(User::factory()->reader(AccessModule::Catalog)->create())
        ->postJson(route('solutions.relationships.store', $sap), ['other_solution_id' => $other->id, 'direction' => 'both'])
        ->assertForbidden();

    $this->actingAs(manEditor())
        ->deleteJson(route('solutions.relationships.destroy', [$other, $record]))
        ->assertNotFound();

    expect(SolutionRelationship::count())->toBe(1);
});

it('overrides a documented pair, and removing the record brings the documentation back', function () {
    $sap = manSolution('sap', 'SAP');
    $vtex = manSolution('vtex', 'VTEX');
    manDocumented($sap, $vtex);

    $this->actingAs(manEditor())
        ->putJson(route('solutions.relationships.override', [$sap, 'other_solution_id' => $vtex->id]), ['direction' => 'both'])
        ->assertOk();

    $relationship = app(SolutionRelationshipService::class)->forSolution($sap)->sole();

    expect($relationship->direction)->toBe(RelationshipDirection::Both)
        ->and($relationship->source())->toBe('adjusted')
        ->and($relationship->documented)->toBe(RelationshipDirection::Outgoing)
        ->and($relationship->evidence)->toHaveCount(1);

    $this->actingAs(manEditor())
        ->deleteJson(route('solutions.relationships.destroy', [$sap, SolutionRelationship::sole()]))
        ->assertOk();

    expect(manDirection($sap, $vtex))->toBe(RelationshipDirection::Outgoing);
});

it('draws the editing affordances for an editor only, and no remove on a documented-only row', function () {
    $sap = manSolution('sap', 'SAP');
    $vtex = manSolution('vtex', 'VTEX');
    $fresh = manSolution('fresh', 'Freshdesk');
    manDocumented($sap, $vtex);
    app(SaveManualRelationship::class)->handle($sap, $fresh, RelationshipDirection::Both);

    $html = $this->actingAs(manEditor())->get(route('solutions.show', $sap))->assertOk()->getContent();

    expect($html)->toContain('Adicionar interconexão')
        // The direction selects carry their options (a component method named
        // like the view key once replaced them with nothing).
        ->toContain('→ esta chama a outra')
        ->toContain('⟷ chamam uma à outra')
        ->toContain('>manual</span>')
        // The override's URL, inside the editor's JSON config (slashes escaped).
        ->toContain('relationships?other_solution_id=' . $vtex->id)
        ->toContain('solution-relationship-remove-' . SolutionRelationship::sole()->id)
        ->toContain('Remover a interconexão entre SAP e Freshdesk?')
        // One ✕ only: the documented row (VTEX) has none.
        ->and(substr_count($html, 'data-ak-ajax="solution-relationship-remove-'))->toBe(1);

    $reader = $this->actingAs(User::factory()->reader(AccessModule::Catalog)->create())->get(route('solutions.show', $sap))->assertOk()->getContent();

    expect($reader)->toContain('SAP chama VTEX')
        ->not->toContain('Adicionar interconexão')
        ->not->toContain('solution-relationship-remove-');
});

it('deletes a solution\'s interconnections with it, and counts them in the catalog and on the map', function () {
    $sap = manSolution('sap', 'SAP');
    $vtex = manSolution('vtex', 'VTEX');
    app(SaveManualRelationship::class)->handle($sap, $vtex, RelationshipDirection::Outgoing);

    expect(app(SolutionRelationshipService::class)->total())->toBe(1);

    $edges = $this->actingAs(User::factory()->create())->getJson(route('solutions.map.data'))->assertOk()->json('edges');
    expect($edges)->toHaveCount(1)
        ->and($edges[0]['direction'])->toBe('unidirectional')
        ->and($edges[0]['source'])->toBe("sol-{$sap->id}")
        ->and($edges[0]['manual'])->not->toBeNull();

    $vtex->delete();
    expect(SolutionRelationship::count())->toBe(0);
});

/* ------------------------------------------------------------------ */
/*  Through the MCP */
/* ------------------------------------------------------------------ */

it('sets a relationship through the MCP, idempotently, and says when it contradicts the documentation', function () {
    $sap = manSolution('sap', 'SAP');
    $vtex = manSolution('vtex', 'VTEX');
    manDocumented($sap, $vtex);
    $editor = manEditor();

    $first = manTool($editor, 'set_solution_relationship', ['solution' => 'vtex', 'other' => 'sap', 'direction' => 'chama']);

    expect($first['isError'])->toBeFalse()
        ->and($first['data']['message'])->toContain('VTEX chama SAP')
        ->and($first['data']['message'])->toContain('A documentação dizia outra coisa (SAP chama VTEX)')
        ->and(collect($first['data']['solution']['relationships'])->firstWhere('solution.slug', 'sap'))
        ->toMatchArray(['direction' => 'chama', 'source' => 'cadastro sobre a documentação', 'documented_direction' => 'é chamada por']);

    $again = manTool($editor, 'set_solution_relationship', ['solution' => 'vtex', 'other' => 'sap', 'direction' => 'chama']);

    expect($again['data']['message'])->toContain('nada mudou')
        ->and(McpWrite::count())->toBe(1)
        ->and(McpWrite::sole()->after)->toBe(['relationships' => [['solution' => 'sap', 'direction' => 'outgoing']]]);
});

it('removes only a recorded relationship through the MCP, and reverts', function () {
    // A revert re-validates the solution's columns (RevertMcpWrite), which
    // need the attribute vocabulary.
    $this->seed(AttributeOptionSeeder::class);
    $sap = manSolution('sap', 'SAP');
    $vtex = manSolution('vtex', 'VTEX');
    $fresh = manSolution('fresh', 'Freshdesk');
    $page = manDocumented($sap, $fresh);
    $editor = manEditor();
    $admin = User::factory()->admin()->create();

    $refused = manTool($editor, 'remove_solution_relationship', ['solution' => 'sap', 'other' => 'fresh']);
    expect($refused['isError'])->toBeTrue()
        ->and($refused['text'])->toContain('vem da documentação')
        ->and($refused['text'])->toContain($page->slug);

    manTool($editor, 'set_solution_relationship', ['solution' => 'sap', 'other' => 'vtex', 'direction' => 'mútua']);
    manTool($editor, 'remove_solution_relationship', ['solution' => 'sap', 'other' => 'vtex']);

    expect(SolutionRelationship::count())->toBe(0)
        ->and(McpWrite::count())->toBe(2);

    // Reverting the removal puts the record back.
    $this->actingAs($admin, 'web')
        ->postJson(route('mcp-writes.revert', McpWrite::latest('id')->first()))
        ->assertOk()
        ->assertJsonPath('type', 'success');

    expect(manDirection($sap, $vtex))->toBe(RelationshipDirection::Both);
});

it('hides the relationship write tools from a connection that may not write', function () {
    manSolution('sap', 'SAP');

    Passport::actingAs(manEditor(), [OAuth::SCOPE]);
    $tools = collect($this->postJson(route('mcp.handle'), ['jsonrpc' => '2.0', 'id' => 1, 'method' => 'tools/list'])
        ->assertOk()->json('result.tools'))->pluck('name');

    expect($tools)->not->toContain('set_solution_relationship')
        ->not->toContain('remove_solution_relationship');

    Passport::actingAs(manEditor(), [OAuth::SCOPE, OAuth::WRITE_SCOPE]);
    $tools = collect($this->postJson(route('mcp.handle'), ['jsonrpc' => '2.0', 'id' => 1, 'method' => 'tools/list'])
        ->assertOk()->json('result.tools'))->pluck('name');

    expect($tools)->toContain('set_solution_relationship', 'remove_solution_relationship');
});
