<?php

use App\Enums\UserRole;
use App\Models\Diagram;
use App\Models\Notebook;
use App\Models\Solution;
use App\Models\User;
use Illuminate\Foundation\Testing\LazilyRefreshDatabase;
use Illuminate\Support\Facades\Route;

uses(LazilyRefreshDatabase::class);

function diagramAdmin(): User
{
    return User::factory()->create(['role' => UserRole::Admin->value]);
}

it('creates a blank diagram inside a caderno and goes straight to its canvas', function () {
    $notebook = Notebook::factory()->create();

    $response = $this->actingAs(diagramAdmin())
        ->postJson(route('notebooks.diagrams.store', $notebook), ['name' => 'Fluxo novo'])
        ->assertOk()
        ->assertJson(['type' => 'success']);

    $diagram = Diagram::where('name', 'Fluxo novo')->firstOrFail();

    // One free-text root block named after it, no participants — and it
    // belongs to the caderno it was created in.
    expect($diagram->status->value)->toBe('planned')
        ->and($diagram->notebook_id)->toBe($notebook->id)
        ->and($diagram->chain['nodes'])->toBe([['solution_id' => null, 'label' => 'Fluxo novo', 'kind' => 'system']])
        ->and($diagram->participants)->toBeEmpty()
        ->and($response->json('redirect'))->toBe(route('diagrams.show', $diagram));
});

it('asks for a name before creating a blank diagram', function () {
    $response = $this->actingAs(diagramAdmin())
        ->postJson(route('notebooks.diagrams.store', Notebook::factory()->create()), ['name' => ''])
        ->assertStatus(422);

    expect($response->json('message'))->toBe('Dê um nome ao diagrama.')
        ->and(Diagram::count())->toBe(0);
});

it('no longer creates a diagram outside a caderno', function () {
    // `diagrams.store` was the door a drawing that belonged to nothing came in
    // through, from /diagrams and from a solution's page.
    expect(Route::has('diagrams.store'))->toBeFalse();

    $this->actingAs(diagramAdmin())
        ->postJson('/diagrams', ['name' => 'Solto'])
        ->assertStatus(405);
});

it('forbids non-admins from creating a diagram', function () {
    $this->actingAs(User::factory()->create()) // viewer
        ->postJson(route('notebooks.diagrams.store', Notebook::factory()->create()), ['name' => 'Nova'])
        ->assertForbidden();
});

it('lists only the caderno own diagrams in its modal, with the systems each names', function () {
    $notebook = Notebook::factory()->create();
    $solution = Solution::factory()->create(['name' => 'SAP CPI']);
    $mine = Diagram::factory()->forNotebook($notebook)->create(['name' => 'Deste caderno']);
    $mine->participants()->attach($solution->id, ['position' => 0]);
    Diagram::factory()->create(['name' => 'De outro caderno']);

    $content = $this->actingAs(diagramAdmin())
        ->getJson(route('notebooks.diagrams.index', $notebook))
        ->assertOk()
        ->json('content');

    expect($content)->toContain('Deste caderno')
        ->toContain('SAP CPI')
        ->toContain(e('{% diagram slug="' . $mine->slug . '" %}'))
        ->not->toContain('De outro caderno');
});

it('deletes a caderno diagrams along with it', function () {
    $notebook = Notebook::factory()->create();
    $diagram = Diagram::factory()->forNotebook($notebook)->create();

    $notebook->delete();

    $this->assertModelMissing($diagram);
});

it('renames and changes the status of an existing diagram without touching its chain', function () {
    $solution = Solution::factory()->create();
    $diagram = Diagram::factory()->create([
        'name'   => 'Antigo nome',
        'status' => 'active',
        'chain'  => ['nodes' => [['solution_id' => $solution->id, 'label' => null]], 'edges' => []],
    ]);
    attachParticipants($diagram, [[$solution, 0]]);

    $this->actingAs(diagramAdmin())
        ->patchJson(route('diagrams.update', $diagram), [
            'name'   => 'Novo nome',
            'status' => 'deprecated',
        ])
        ->assertOk()
        ->assertJson(['type' => 'success']);

    $diagram->refresh();

    expect($diagram->name)->toBe('Novo nome')
        ->and($diagram->status->value)->toBe('deprecated')
        ->and($diagram->chain)->toBe(['nodes' => [['solution_id' => $solution->id, 'label' => null]], 'edges' => []]);
});

it('updates only the field the inline editor sent, leaving the other one alone', function () {
    $solution = Solution::factory()->create();
    $diagram = Diagram::factory()->create(['name' => 'Nome preservado', 'status' => 'planned']);
    attachParticipants($diagram, [[$solution, 0]]);

    // The top bar's `x-ui.inline-edit` confirms one field at a time.
    $this->actingAs(diagramAdmin())
        ->patchJson(route('diagrams.update', $diagram), ['status' => 'active'])
        ->assertOk();

    $diagram->refresh();

    expect($diagram->status->value)->toBe('active')
        ->and($diagram->name)->toBe('Nome preservado');
});

it('refreshes the top bar and the pages rail after an inline meta edit', function () {
    $solution = Solution::factory()->create();
    $diagram = Diagram::factory()->create(['name' => 'Antigo', 'status' => 'planned']);
    attachParticipants($diagram, [[$solution, 0]]);

    $response = $this->actingAs(diagramAdmin())
        ->patchJson(route('diagrams.update', $diagram), ['name' => 'Novo'])
        ->assertOk();

    expect(collect($response->json('updatableSlots'))->pluck('id')->all())
        // The top bar names it, and so does the index someone lands on after
        // leaving this page. `ajax-slot.js` no-ops on the id that isn't here.
        ->toBe(['diagram-meta-slot', 'diagrams-index-slot'])
        ->and($response->json('updatableSlots.0.content'))->toContain('Novo');
});

it('rejects blanking a field it was given', function () {
    $solution = Solution::factory()->create();
    $diagram = Diagram::factory()->create();
    attachParticipants($diagram, [[$solution, 0]]);

    $this->actingAs(diagramAdmin())
        ->patchJson(route('diagrams.update', $diagram), ['name' => ''])
        ->assertStatus(422);
});

it('forbids non-admins from renaming a diagram', function () {
    $solution = Solution::factory()->create();
    $diagram = Diagram::factory()->create();
    attachParticipants($diagram, [[$solution, 0]]);

    $this->actingAs(User::factory()->create()) // viewer
        ->patchJson(route('diagrams.update', $diagram), [
            'name'   => 'Tentativa',
            'status' => 'active',
        ])
        ->assertForbidden();
});
