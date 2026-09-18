<?php

use App\Models\AttributeOption;
use Illuminate\Foundation\Testing\LazilyRefreshDatabase;
use Illuminate\Support\Facades\Cache;

uses(LazilyRefreshDatabase::class);

/**
 * The memo in front of the attribute-option cache, and the one property no
 * other test can see: how long it lives.
 */
it('lets go of the memo when a queue worker resets between jobs', function () {
    AttributeOption::create(['group' => 'status', 'value' => 'active', 'label' => 'Ativo']);

    expect(AttributeOption::labelFor('status', 'active'))->toBe('Ativo');

    // A rename made the way another PROCESS would make it: the row and the
    // day-long cache entry change, and nothing in this container hears about
    // it. The only thing that can clear the memo here is the reset
    // `QueueServiceProvider` runs between jobs — which unsets scoped bindings
    // and nothing else, so an `instance()` binding would go on answering with
    // the old label for the worker's whole life.
    AttributeOption::withoutEvents(fn () => AttributeOption::query()->update(['label' => 'Em produção']));
    Cache::forget('attribute_options.all');

    expect(AttributeOption::labelFor('status', 'active'))->toBe('Ativo', 'the memo is supposed to hold for one job');

    app()->forgetScopedInstances();

    expect(AttributeOption::labelFor('status', 'active'))->toBe('Em produção');
});

it('shows a save through the cache inside the same request', function () {
    AttributeOption::create(['group' => 'status', 'value' => 'active', 'label' => 'Ativo']);
    AttributeOption::labelFor('status', 'active');

    // `saved` has to drop all three layers, not just the store: the admin adds
    // a value and the list that comes back must have it.
    AttributeOption::create(['group' => 'status', 'value' => 'evaluating', 'label' => 'Em avaliação']);

    expect(AttributeOption::labelFor('status', 'evaluating'))->toBe('Em avaliação')
        ->and(AttributeOption::options('status'))->toHaveCount(2);
});

it('stops serving an option that was deleted', function () {
    $option = AttributeOption::create(['group' => 'status', 'value' => 'active', 'label' => 'Ativo']);
    AttributeOption::labelFor('status', 'active');

    $option->delete();

    expect(AttributeOption::labelFor('status', 'active'))->toBeNull();
});
