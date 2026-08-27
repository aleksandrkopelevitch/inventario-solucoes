<?php

use App\Models\Diagram;
use App\Models\DocumentationPage;
use App\Models\Solution;
use Illuminate\Foundation\Testing\LazilyRefreshDatabase;

uses(LazilyRefreshDatabase::class);

it('filters undocumented solutions by real content', function () {
    $documented = Solution::factory()->create();
    DocumentationPage::factory()->for(notebookFor($documented))->create(['documentation' => '# Doc']);

    $emptyPage = Solution::factory()->create();
    DocumentationPage::factory()->for(notebookFor($emptyPage))->create(['documentation' => '']);

    $noPages = Solution::factory()->create();

    $ids = Solution::query()->filter(['undocumented' => true])->pluck('id');

    expect($ids)->not->toContain($documented->id)
        ->and($ids)->toContain($emptyPage->id)
        ->and($ids)->toContain($noPages->id);
});

it('counts active in/out diagrams via scopeWithDiagramCounts', function () {
    $hub = Solution::factory()->create();
    $other = Solution::factory()->create();

    Diagram::factory()->active()->create(['source_solution_id' => $hub->id, 'target_solution_id' => $other->id]);
    Diagram::factory()->active()->create(['source_solution_id' => $other->id, 'target_solution_id' => $hub->id]);
    Diagram::factory()->create(['source_solution_id' => $hub->id, 'target_solution_id' => $other->id]); // planned, doesn't count

    $loaded = Solution::withDiagramCounts()->find($hub->id);

    expect((int) $loaded->active_out)->toBe(1)
        ->and((int) $loaded->active_in)->toBe(1);
});
