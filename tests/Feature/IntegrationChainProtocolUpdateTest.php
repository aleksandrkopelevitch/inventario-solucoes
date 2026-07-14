<?php

use App\Enums\UserRole;
use App\Models\Integration;
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

    $integration = Integration::factory()->create([
        'chain' => [
            'nodes' => [['solution_id' => $svl->id, 'label' => null], ['solution_id' => $sap->id, 'label' => null]],
            'edges' => [['from' => 0, 'to' => 1, 'arrow' => '->', 'protocol' => null]],
        ],
    ]);
    attachParticipants($integration, [[$svl, 0], [$sap, 1]]);

    $response = $this->actingAs(protocolUpdateAdmin())
        ->patchJson(route('solutions.integrations.chain.protocol.update', [$svl, $integration, 0]), [
            'protocol' => 'rest',
        ])
        ->assertOk()
        ->assertJson(['type' => 'success']);

    expect($response->json('protocol'))->toBe(['value' => 'rest', 'label' => 'REST']);

    $integration->refresh();

    expect($integration->chain['edges'][0]['protocol'])->toBe('rest')
        ->and($integration->protocol->value)->toBe('rest');
});

it('clears the protocol of a chain edge back to null', function () {
    $svl = Solution::factory()->create(['name' => 'SVL']);
    $sap = Solution::factory()->create(['name' => 'SAP']);

    $integration = Integration::factory()->create([
        'chain' => [
            'nodes' => [['solution_id' => $svl->id, 'label' => null], ['solution_id' => $sap->id, 'label' => null]],
            'edges' => [['from' => 0, 'to' => 1, 'arrow' => '->', 'protocol' => 'rest']],
        ],
    ]);
    attachParticipants($integration, [[$svl, 0], [$sap, 1]]);

    $response = $this->actingAs(protocolUpdateAdmin())
        ->patchJson(route('solutions.integrations.chain.protocol.update', [$svl, $integration, 0]), [
            'protocol' => '',
        ])
        ->assertOk();

    expect($response->json('protocol'))->toBeNull();

    $integration->refresh();

    expect($integration->chain['edges'][0]['protocol'])->toBeNull()
        ->and($integration->protocol)->toBeNull();
});

it('sets a protocol on a legacy edge that has no protocol key at all', function () {
    $svl = Solution::factory()->create(['name' => 'SVL']);
    $digibee = Solution::factory()->create(['name' => 'Digibee']);
    $sap = Solution::factory()->create(['name' => 'SAP']);

    $integration = Integration::factory()->create([
        'chain' => [
            'nodes' => [['solution_id' => $svl->id, 'label' => null], ['solution_id' => $digibee->id, 'label' => null], ['solution_id' => $sap->id, 'label' => null]],
            'edges' => [
                ['from' => 0, 'to' => 1, 'arrow' => '->'], // sem 'protocol' — edge legada
                ['from' => 1, 'to' => 2, 'arrow' => '->'],
            ],
        ],
    ]);
    attachParticipants($integration, [[$svl, 0], [$digibee, 1], [$sap, 2]]);

    $this->actingAs(protocolUpdateAdmin())
        ->patchJson(route('solutions.integrations.chain.protocol.update', [$svl, $integration, 1]), [
            'protocol' => 'sftp',
        ])
        ->assertOk();

    $chain = $integration->fresh()->chain;
    expect($chain['edges'][0]['protocol'] ?? null)->toBeNull()
        ->and($chain['edges'][1]['protocol'])->toBe('sftp');
});

it('also updates the arrow direction of a chain edge, resyncing bidirectionality', function () {
    $svl = Solution::factory()->create(['name' => 'SVL']);
    $sap = Solution::factory()->create(['name' => 'SAP']);

    $integration = Integration::factory()->create([
        'chain' => [
            'nodes' => [['solution_id' => $svl->id, 'label' => null], ['solution_id' => $sap->id, 'label' => null]],
            'edges' => [['from' => 0, 'to' => 1, 'arrow' => '->', 'protocol' => null]],
        ],
    ]);
    attachParticipants($integration, [[$svl, 0], [$sap, 1]]);

    $response = $this->actingAs(protocolUpdateAdmin())
        ->patchJson(route('solutions.integrations.chain.protocol.update', [$svl, $integration, 0]), [
            'protocol' => 'rest',
            'arrow'    => '<->',
        ])
        ->assertOk()
        ->assertJson(['type' => 'success']);

    expect($response->json('arrow'))->toBe('<->')
        ->and($response->json('protocol'))->toBe(['value' => 'rest', 'label' => 'REST']);

    $integration->refresh();

    expect($integration->chain['edges'][0])->toBe(['from' => 0, 'to' => 1, 'arrow' => '<->', 'protocol' => 'rest'])
        ->and($integration->direction->value)->toBe('bidirectional');
});

it('leaves the arrow untouched when only the protocol is sent', function () {
    $svl = Solution::factory()->create(['name' => 'SVL']);
    $sap = Solution::factory()->create(['name' => 'SAP']);

    $integration = Integration::factory()->create([
        'chain' => [
            'nodes' => [['solution_id' => $svl->id, 'label' => null], ['solution_id' => $sap->id, 'label' => null]],
            'edges' => [['from' => 0, 'to' => 1, 'arrow' => '<-', 'protocol' => null]],
        ],
    ]);
    attachParticipants($integration, [[$svl, 0], [$sap, 1]]);

    $this->actingAs(protocolUpdateAdmin())
        ->patchJson(route('solutions.integrations.chain.protocol.update', [$svl, $integration, 0]), [
            'protocol' => 'sftp',
        ])
        ->assertOk();

    expect($integration->fresh()->chain['edges'][0]['arrow'])->toBe('<-');
});

it('rejects an invalid arrow direction on a chain edge', function () {
    $svl = Solution::factory()->create(['name' => 'SVL']);
    $sap = Solution::factory()->create(['name' => 'SAP']);

    $integration = Integration::factory()->create([
        'chain' => [
            'nodes' => [['solution_id' => $svl->id, 'label' => null], ['solution_id' => $sap->id, 'label' => null]],
            'edges' => [['from' => 0, 'to' => 1, 'arrow' => '->', 'protocol' => null]],
        ],
    ]);
    attachParticipants($integration, [[$svl, 0], [$sap, 1]]);

    $this->actingAs(protocolUpdateAdmin())
        ->patchJson(route('solutions.integrations.chain.protocol.update', [$svl, $integration, 0]), [
            'arrow' => '=>',
        ])
        ->assertStatus(422);
});

it('404s for an edge index outside the chain', function () {
    $svl = Solution::factory()->create(['name' => 'SVL']);
    $sap = Solution::factory()->create(['name' => 'SAP']);

    $integration = Integration::factory()->create([
        'chain' => [
            'nodes' => [['solution_id' => $svl->id, 'label' => null], ['solution_id' => $sap->id, 'label' => null]],
            'edges' => [['from' => 0, 'to' => 1, 'arrow' => '->', 'protocol' => null]],
        ],
    ]);
    attachParticipants($integration, [[$svl, 0], [$sap, 1]]);

    $this->actingAs(protocolUpdateAdmin())
        ->patchJson(route('solutions.integrations.chain.protocol.update', [$svl, $integration, 3]), [
            'protocol' => 'rest',
        ])
        ->assertNotFound();
});

it('rejects an invalid protocol value', function () {
    $svl = Solution::factory()->create(['name' => 'SVL']);
    $sap = Solution::factory()->create(['name' => 'SAP']);

    $integration = Integration::factory()->create([
        'chain' => [
            'nodes' => [['solution_id' => $svl->id, 'label' => null], ['solution_id' => $sap->id, 'label' => null]],
            'edges' => [['from' => 0, 'to' => 1, 'arrow' => '->', 'protocol' => null]],
        ],
    ]);
    attachParticipants($integration, [[$svl, 0], [$sap, 1]]);

    $this->actingAs(protocolUpdateAdmin())
        ->patchJson(route('solutions.integrations.chain.protocol.update', [$svl, $integration, 0]), [
            'protocol' => 'carrier-pigeon',
        ])
        ->assertStatus(422);
});

it('forbids non-admins from updating a chain edge protocol', function () {
    $svl = Solution::factory()->create(['name' => 'SVL']);
    $sap = Solution::factory()->create(['name' => 'SAP']);

    $integration = Integration::factory()->create([
        'chain' => [
            'nodes' => [['solution_id' => $svl->id, 'label' => null], ['solution_id' => $sap->id, 'label' => null]],
            'edges' => [['from' => 0, 'to' => 1, 'arrow' => '->', 'protocol' => null]],
        ],
    ]);
    attachParticipants($integration, [[$svl, 0], [$sap, 1]]);

    $this->actingAs(User::factory()->create()) // viewer
        ->patchJson(route('solutions.integrations.chain.protocol.update', [$svl, $integration, 0]), [
            'protocol' => 'rest',
        ])
        ->assertForbidden();
});
