<?php

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
