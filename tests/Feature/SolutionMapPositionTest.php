<?php

use App\Enums\UserRole;
use App\Models\Solution;
use App\Models\User;
use Illuminate\Foundation\Testing\LazilyRefreshDatabase;

uses(LazilyRefreshDatabase::class);

function mapPositionAdmin(): User
{
    return User::factory()->create(['role' => UserRole::Admin->value]);
}

it('auto-saves a dragged hub position to the solution', function () {
    $solution = Solution::factory()->create();

    $this->actingAs(mapPositionAdmin())
        ->patchJson(route('solutions.map.position.update', $solution), [
            'x' => 123.4,
            'y' => 56.7,
        ])
        ->assertOk()
        ->assertJson(['message' => 'Posição salva.']);

    expect($solution->fresh()->map_position)->toBe(['x' => 123.4, 'y' => 56.7]);
});

it('overwrites a previously saved position on a later drag', function () {
    $solution = Solution::factory()->create(['map_position' => ['x' => 1, 'y' => 1]]);

    $this->actingAs(mapPositionAdmin())
        ->patchJson(route('solutions.map.position.update', $solution), ['x' => 200, 'y' => 300])
        ->assertOk();

    expect($solution->fresh()->map_position)->toBe(['x' => 200, 'y' => 300]);
});

it('rejects a position missing x or y', function () {
    $solution = Solution::factory()->create();

    $this->actingAs(mapPositionAdmin())
        ->patchJson(route('solutions.map.position.update', $solution), ['x' => 10])
        ->assertStatus(422);

    expect($solution->fresh()->map_position)->toBeNull();
});

it('forbids non-admins from saving a hub position', function () {
    $solution = Solution::factory()->create();

    $this->actingAs(User::factory()->create()) // viewer
        ->patchJson(route('solutions.map.position.update', $solution), ['x' => 10, 'y' => 20])
        ->assertForbidden();

    expect($solution->fresh()->map_position)->toBeNull();
});
