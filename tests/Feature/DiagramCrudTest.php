<?php

use App\Enums\UserRole;
use App\Models\Diagram;
use App\Models\Solution;
use App\Models\User;
use Illuminate\Foundation\Testing\LazilyRefreshDatabase;

uses(LazilyRefreshDatabase::class);

function diagramAdmin(): User
{
    return User::factory()->create(['role' => UserRole::Admin->value]);
}

it('seeds the root block with the solution the creation came from', function () {
    $solution = Solution::factory()->create(['name' => 'SVL']);

    $response = $this->actingAs(diagramAdmin())
        ->postJson(route('diagrams.store'), ['name' => 'Nova integração', 'solution_id' => $solution->id])
        ->assertOk()
        ->assertJson(['type' => 'success']);

    $diagram = Diagram::where('name', 'Nova integração')->firstOrFail();

    expect($diagram->status->value)->toBe('planned')
        ->and($diagram->chain)->toBe([
            'nodes' => [['solution_id' => $solution->id, 'label' => null, 'kind' => 'system']],
            'edges' => [],
        ])
        ->and($diagram->participants->pluck('id')->all())->toBe([$solution->id])
        // Creating one takes the user straight to its canvas — there's nothing
        // about a brand-new diagram the list it came from could show.
        ->and($response->json('redirect'))->toBe(route('diagrams.show', $diagram));
});

it('starts from a free-text root block when created without a solution', function () {
    // The diagrams index has no solution in context, so the root is the
    // diagram's own name as free text — and it derives no participants.
    $this->actingAs(diagramAdmin())
        ->postJson(route('diagrams.store'), ['name' => 'Fluxo novo'])
        ->assertOk();

    $diagram = Diagram::where('name', 'Fluxo novo')->firstOrFail();

    expect($diagram->chain['nodes'])->toBe([['solution_id' => null, 'label' => 'Fluxo novo', 'kind' => 'system']])
        ->and($diagram->participants)->toBeEmpty();
});

it('falls back to the solution name when creating a diagram without a name', function () {
    $solution = Solution::factory()->create(['name' => 'SVL']);

    $this->actingAs(diagramAdmin())
        ->postJson(route('diagrams.store'), ['solution_id' => $solution->id])
        ->assertOk();

    $this->assertDatabaseHas('diagrams', ['name' => 'SVL']);
});

it('forbids non-admins from creating a diagram', function () {
    $this->actingAs(User::factory()->create()) // viewer
        ->postJson(route('diagrams.store'), ['name' => 'Nova'])
        ->assertForbidden();
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
