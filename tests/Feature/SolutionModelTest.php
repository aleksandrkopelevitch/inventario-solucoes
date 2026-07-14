<?php

use App\Models\Integration;
use App\Models\Solution;
use Illuminate\Foundation\Testing\LazilyRefreshDatabase;

uses(LazilyRefreshDatabase::class);

it('filters undocumented solutions by real content', function () {
    $documented = Solution::factory()->create(['documentation' => '# Doc']);
    $empty = Solution::factory()->create(['documentation' => '']);
    $null = Solution::factory()->create(['documentation' => null]);

    $ids = Solution::query()->filter(['undocumented' => true])->pluck('id');

    expect($ids)->not->toContain($documented->id)
        ->and($ids)->toContain($empty->id)
        ->and($ids)->toContain($null->id);
});

it('counts active in/out integrations via scopeWithIntegrationCounts', function () {
    $hub = Solution::factory()->create();
    $other = Solution::factory()->create();

    Integration::factory()->active()->create(['source_solution_id' => $hub->id, 'target_solution_id' => $other->id]);
    Integration::factory()->active()->create(['source_solution_id' => $other->id, 'target_solution_id' => $hub->id]);
    Integration::factory()->create(['source_solution_id' => $hub->id, 'target_solution_id' => $other->id]); // planned, não conta

    $loaded = Solution::withIntegrationCounts()->find($hub->id);

    expect((int) $loaded->active_out)->toBe(1)
        ->and((int) $loaded->active_in)->toBe(1);
});
