<?php

use App\View\Components\Examples\SlotExample;

it('wraps a component as an updatable-slot payload via slot()', function () {
    $slot = SlotExample::slot(3);

    expect($slot)->toHaveKeys(['id', 'content'])
        ->and($slot['id'])->toBe(SlotExample::DOM_ID)
        ->and($slot['content'])->toContain('Itens: 3')
        ->and($slot['content'])->toContain('id="' . SlotExample::DOM_ID . '"');
});

it('renders to an arbitrary slot id via the Renderable trait', function () {
    $payload = (new SlotExample(7))->toSlot('header-widget-slot|sidebar-widget-slot');

    expect($payload['id'])->toBe('header-widget-slot|sidebar-widget-slot')
        ->and($payload['content'])->toContain('Itens: 7');
});
