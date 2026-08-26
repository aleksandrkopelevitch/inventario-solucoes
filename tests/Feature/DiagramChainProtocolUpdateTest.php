<?php

use App\Enums\UserRole;
use App\Models\Diagram;
use App\Models\Solution;
use App\Models\User;
use Illuminate\Foundation\Testing\LazilyRefreshDatabase;

uses(LazilyRefreshDatabase::class);

function protocolUpdateAdmin(): User
{
    return User::factory()->create(['role' => UserRole::Admin->value]);
}

it('sets the protocol of a chain edge, resyncing the scalar summary protocol', function () {
    $svl = Solution::factory()->create(['name' => 'SVL']);
    $sap = Solution::factory()->create(['name' => 'SAP']);

    $diagram = Diagram::factory()->create([
        'chain' => [
            'nodes' => [['solution_id' => $svl->id, 'label' => null], ['solution_id' => $sap->id, 'label' => null]],
            'edges' => [['from' => 0, 'to' => 1, 'arrow' => '->', 'protocol' => null]],
        ],
    ]);
    attachParticipants($diagram, [[$svl, 0], [$sap, 1]]);

    $response = $this->actingAs(protocolUpdateAdmin())
        ->patchJson(route('diagrams.chain.protocol.update', [$diagram, 0]), [
            'protocol' => 'rest',
        ])
        ->assertOk()
        ->assertJson(['type' => 'success']);

    expect($response->json('protocol'))->toBe(['value' => 'rest', 'label' => 'REST']);

    $diagram->refresh();

    expect($diagram->chain['edges'][0]['protocol'])->toBe('rest')
        ->and($diagram->protocol)->toBe('rest');
});

it('clears the protocol of a chain edge back to null', function () {
    $svl = Solution::factory()->create(['name' => 'SVL']);
    $sap = Solution::factory()->create(['name' => 'SAP']);

    $diagram = Diagram::factory()->create([
        'chain' => [
            'nodes' => [['solution_id' => $svl->id, 'label' => null], ['solution_id' => $sap->id, 'label' => null]],
            'edges' => [['from' => 0, 'to' => 1, 'arrow' => '->', 'protocol' => 'rest']],
        ],
    ]);
    attachParticipants($diagram, [[$svl, 0], [$sap, 1]]);

    $response = $this->actingAs(protocolUpdateAdmin())
        ->patchJson(route('diagrams.chain.protocol.update', [$diagram, 0]), [
            'protocol' => '',
        ])
        ->assertOk();

    expect($response->json('protocol'))->toBeNull();

    $diagram->refresh();

    expect($diagram->chain['edges'][0]['protocol'])->toBeNull()
        ->and($diagram->protocol)->toBeNull();
});

it('sets a protocol on a legacy edge that has no protocol key at all', function () {
    $svl = Solution::factory()->create(['name' => 'SVL']);
    $digibee = Solution::factory()->create(['name' => 'Digibee']);
    $sap = Solution::factory()->create(['name' => 'SAP']);

    $diagram = Diagram::factory()->create([
        'chain' => [
            'nodes' => [['solution_id' => $svl->id, 'label' => null], ['solution_id' => $digibee->id, 'label' => null], ['solution_id' => $sap->id, 'label' => null]],
            'edges' => [
                ['from' => 0, 'to' => 1, 'arrow' => '->'], // sem 'protocol' — edge legada
                ['from' => 1, 'to' => 2, 'arrow' => '->'],
            ],
        ],
    ]);
    attachParticipants($diagram, [[$svl, 0], [$digibee, 1], [$sap, 2]]);

    $this->actingAs(protocolUpdateAdmin())
        ->patchJson(route('diagrams.chain.protocol.update', [$diagram, 1]), [
            'protocol' => 'sftp',
        ])
        ->assertOk();

    $chain = $diagram->fresh()->chain;
    expect($chain['edges'][0]['protocol'] ?? null)->toBeNull()
        ->and($chain['edges'][1]['protocol'])->toBe('sftp');
});

it('also updates the arrow direction of a chain edge, resyncing bidirectionality', function () {
    $svl = Solution::factory()->create(['name' => 'SVL']);
    $sap = Solution::factory()->create(['name' => 'SAP']);

    $diagram = Diagram::factory()->create([
        'chain' => [
            'nodes' => [['solution_id' => $svl->id, 'label' => null], ['solution_id' => $sap->id, 'label' => null]],
            'edges' => [['from' => 0, 'to' => 1, 'arrow' => '->', 'protocol' => null]],
        ],
    ]);
    attachParticipants($diagram, [[$svl, 0], [$sap, 1]]);

    $response = $this->actingAs(protocolUpdateAdmin())
        ->patchJson(route('diagrams.chain.protocol.update', [$diagram, 0]), [
            'protocol' => 'rest',
            'arrow'    => '<->',
        ])
        ->assertOk()
        ->assertJson(['type' => 'success']);

    expect($response->json('arrow'))->toBe('<->')
        ->and($response->json('protocol'))->toBe(['value' => 'rest', 'label' => 'REST']);

    $diagram->refresh();

    expect($diagram->chain['edges'][0])->toBe(['from' => 0, 'to' => 1, 'arrow' => '<->', 'protocol' => 'rest'])
        ->and($diagram->direction->value)->toBe('bidirectional');
});

it('leaves the arrow untouched when only the protocol is sent', function () {
    $svl = Solution::factory()->create(['name' => 'SVL']);
    $sap = Solution::factory()->create(['name' => 'SAP']);

    $diagram = Diagram::factory()->create([
        'chain' => [
            'nodes' => [['solution_id' => $svl->id, 'label' => null], ['solution_id' => $sap->id, 'label' => null]],
            'edges' => [['from' => 0, 'to' => 1, 'arrow' => '<-', 'protocol' => null]],
        ],
    ]);
    attachParticipants($diagram, [[$svl, 0], [$sap, 1]]);

    $this->actingAs(protocolUpdateAdmin())
        ->patchJson(route('diagrams.chain.protocol.update', [$diagram, 0]), [
            'protocol' => 'sftp',
        ])
        ->assertOk();

    expect($diagram->fresh()->chain['edges'][0]['arrow'])->toBe('<-');
});

it('rejects an invalid arrow direction on a chain edge', function () {
    $svl = Solution::factory()->create(['name' => 'SVL']);
    $sap = Solution::factory()->create(['name' => 'SAP']);

    $diagram = Diagram::factory()->create([
        'chain' => [
            'nodes' => [['solution_id' => $svl->id, 'label' => null], ['solution_id' => $sap->id, 'label' => null]],
            'edges' => [['from' => 0, 'to' => 1, 'arrow' => '->', 'protocol' => null]],
        ],
    ]);
    attachParticipants($diagram, [[$svl, 0], [$sap, 1]]);

    $this->actingAs(protocolUpdateAdmin())
        ->patchJson(route('diagrams.chain.protocol.update', [$diagram, 0]), [
            'arrow' => '=>',
        ])
        ->assertStatus(422);
});

it('404s for an edge index outside the chain', function () {
    $svl = Solution::factory()->create(['name' => 'SVL']);
    $sap = Solution::factory()->create(['name' => 'SAP']);

    $diagram = Diagram::factory()->create([
        'chain' => [
            'nodes' => [['solution_id' => $svl->id, 'label' => null], ['solution_id' => $sap->id, 'label' => null]],
            'edges' => [['from' => 0, 'to' => 1, 'arrow' => '->', 'protocol' => null]],
        ],
    ]);
    attachParticipants($diagram, [[$svl, 0], [$sap, 1]]);

    $this->actingAs(protocolUpdateAdmin())
        ->patchJson(route('diagrams.chain.protocol.update', [$diagram, 3]), [
            'protocol' => 'rest',
        ])
        ->assertNotFound();
});

it('accepts a free-text protocol not in the Protocol enum', function () {
    $svl = Solution::factory()->create(['name' => 'SVL']);
    $sap = Solution::factory()->create(['name' => 'SAP']);

    $diagram = Diagram::factory()->create([
        'chain' => [
            'nodes' => [['solution_id' => $svl->id, 'label' => null], ['solution_id' => $sap->id, 'label' => null]],
            'edges' => [['from' => 0, 'to' => 1, 'arrow' => '->', 'protocol' => null]],
        ],
    ]);
    attachParticipants($diagram, [[$svl, 0], [$sap, 1]]);

    $response = $this->actingAs(protocolUpdateAdmin())
        ->patchJson(route('diagrams.chain.protocol.update', [$diagram, 0]), [
            'protocol' => 'carrier-pigeon',
        ])
        ->assertOk();

    expect($response->json('protocol'))->toBe(['value' => 'carrier-pigeon', 'label' => 'carrier-pigeon']);

    $diagram->refresh();

    expect($diagram->chain['edges'][0]['protocol'])->toBe('carrier-pigeon')
        ->and($diagram->protocol)->toBe('carrier-pigeon');
});

it('rejects a protocol value over the length limit', function () {
    $svl = Solution::factory()->create(['name' => 'SVL']);
    $sap = Solution::factory()->create(['name' => 'SAP']);

    $diagram = Diagram::factory()->create([
        'chain' => [
            'nodes' => [['solution_id' => $svl->id, 'label' => null], ['solution_id' => $sap->id, 'label' => null]],
            'edges' => [['from' => 0, 'to' => 1, 'arrow' => '->', 'protocol' => null]],
        ],
    ]);
    attachParticipants($diagram, [[$svl, 0], [$sap, 1]]);

    $this->actingAs(protocolUpdateAdmin())
        ->patchJson(route('diagrams.chain.protocol.update', [$diagram, 0]), [
            'protocol' => str_repeat('a', 61),
        ])
        ->assertStatus(422);
});

it('forbids non-admins from updating a chain edge protocol', function () {
    $svl = Solution::factory()->create(['name' => 'SVL']);
    $sap = Solution::factory()->create(['name' => 'SAP']);

    $diagram = Diagram::factory()->create([
        'chain' => [
            'nodes' => [['solution_id' => $svl->id, 'label' => null], ['solution_id' => $sap->id, 'label' => null]],
            'edges' => [['from' => 0, 'to' => 1, 'arrow' => '->', 'protocol' => null]],
        ],
    ]);
    attachParticipants($diagram, [[$svl, 0], [$sap, 1]]);

    $this->actingAs(User::factory()->create()) // viewer
        ->patchJson(route('diagrams.chain.protocol.update', [$diagram, 0]), [
            'protocol' => 'rest',
        ])
        ->assertForbidden();
});
