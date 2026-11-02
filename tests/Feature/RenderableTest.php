<?php

use App\Models\Company;
use App\View\Components\Companies\ResultsCount;
use Illuminate\Foundation\Testing\LazilyRefreshDatabase;

uses(LazilyRefreshDatabase::class);

// The Renderable trait through a real slot — the catalog's companies counter —
// rather than through a demo component that nothing on screen renders.

it('wraps a component as an updatable-slot payload via slot()', function () {
    Company::factory()->count(3)->create();

    $slot = ResultsCount::slot();

    expect($slot)->toHaveKeys(['id', 'content'])
        ->and($slot['id'])->toBe(ResultsCount::DOM_ID)
        ->and($slot['content'])->toContain('3</span> empresas')
        ->and($slot['content'])->toContain('id="' . ResultsCount::DOM_ID . '"');
});

it('renders to an arbitrary slot id via the Renderable trait', function () {
    Company::factory()->create();

    $payload = (new ResultsCount)->toSlot('header-widget-slot|sidebar-widget-slot');

    expect($payload['id'])->toBe('header-widget-slot|sidebar-widget-slot')
        ->and($payload['content'])->toContain('1</span> empresa');
});
