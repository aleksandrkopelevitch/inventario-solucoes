<?php

use App\Models\FlowspecChat;
use App\View\Components\Flowspec\Thread;
use Illuminate\Foundation\Testing\LazilyRefreshDatabase;
use Illuminate\Support\Facades\DB;

uses(LazilyRefreshDatabase::class);

it('marks the thread as awaiting a reply from the already-fetched messages, without an extra query', function () {
    $chat = FlowspecChat::factory()->create();
    $chat->messages()->create(['role' => 'user', 'content' => 'oi']);

    $queries = [];
    DB::listen(function ($query) use (&$queries) {
        $queries[] = $query->sql;
    });

    $slot = Thread::slot($chat->refresh());

    expect($slot['content'])->toContain('data-ak-flowspec-poll');

    $selectsOnMessages = collect($queries)->filter(fn ($sql) => str_contains($sql, 'flowspec_messages'));
    expect($selectsOnMessages)->toHaveCount(1);
});

it('marks the thread as not awaiting once the last message is from the assistant', function () {
    $chat = FlowspecChat::factory()->create();
    $chat->messages()->create(['role' => 'user', 'content' => 'oi']);
    $chat->messages()->create(['role' => 'assistant', 'content' => 'pronto']);

    $slot = Thread::slot($chat->refresh());

    expect($slot['content'])->not->toContain('data-ak-flowspec-poll');
});
