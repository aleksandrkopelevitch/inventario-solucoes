<?php

use App\Models\AttributeOption;
use Illuminate\Foundation\Testing\LazilyRefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;

uses(LazilyRefreshDatabase::class);

/**
 * The three layers in front of `attribute_options`, and what each one is for.
 *
 * The whole arrangement landed with no test at all, which is uncomfortable for
 * a cache whose failure mode is silent: one layer too few costs a query per
 * call (548 for one map screen), and one layer too sticky makes a queue worker
 * answer with a label an admin renamed hours ago.
 */
beforeEach(function () {
    AttributeOption::forgetCache();
});

it('answers repeated calls with a single query', function () {
    AttributeOption::create(['group' => 'status', 'value' => 'active', 'label' => 'Ativo']);
    AttributeOption::create(['group' => 'category', 'value' => 'erp', 'label' => 'ERP']);
    AttributeOption::forgetCache();

    DB::enableQueryLog();
    DB::flushQueryLog();

    // What one map screen does: the same handful of groups, over and over.
    for ($i = 0; $i < 20; $i++) {
        AttributeOption::labelFor('status', 'active');
        AttributeOption::labelFor('category', 'erp');
        AttributeOption::options('status');
    }

    expect(DB::getQueryLog())->toHaveCount(1);

    DB::disableQueryLog();
});

it('lets the request that renamed an option see the new label', function () {
    $option = AttributeOption::create(['group' => 'status', 'value' => 'active', 'label' => 'Ativo']);

    expect(AttributeOption::labelFor('status', 'active'))->toBe('Ativo');

    $option->update(['label' => 'Em uso']);
    AttributeOption::forgetCache();

    // `forgetCache()` has to drop all three: the container memo, the memo
    // store's copy and the day-long entry behind it. Miss one and the screen
    // that just saved the rename still shows the old label.
    expect(AttributeOption::labelFor('status', 'active'))->toBe('Em uso');
});

it('drops the memo between queue jobs, so a worker stops answering with a stale label', function () {
    // This is the whole reason the binding is `scoped()` rather than
    // `instance()`: `QueueServiceProvider` resets a worker between jobs with
    // `forgetScopedInstances()`, which only unsets keys registered as scoped.
    // Bound with `instance()`, a worker went on answering with the label it
    // first read until supervisor restarted it.
    AttributeOption::create(['group' => 'status', 'value' => 'active', 'label' => 'Ativo']);

    expect(AttributeOption::labelFor('status', 'active'))->toBe('Ativo');

    // Another process renames it and clears the shared cache — which is all
    // the web request that saved the rename can do for a running worker.
    DB::table('attribute_options')->where('value', 'active')->update(['label' => 'Em uso']);
    Cache::flush();

    // Still inside the same job: the memo is per scope, on purpose.
    expect(AttributeOption::labelFor('status', 'active'))->toBe('Ativo');

    app()->forgetScopedInstances();

    expect(AttributeOption::labelFor('status', 'active'))->toBe('Em uso');
});

it('answers null for a value that no longer exists', function () {
    AttributeOption::create(['group' => 'status', 'value' => 'active', 'label' => 'Ativo']);

    expect(AttributeOption::labelFor('status', 'nao-existe'))->toBeNull()
        ->and(AttributeOption::labelFor('grupo-nenhum', 'active'))->toBeNull()
        ->and(AttributeOption::labelFor('status', null))->toBeNull();
});
