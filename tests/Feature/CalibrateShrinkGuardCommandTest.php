<?php

use App\Models\DocumentationChat;
use App\Models\DocumentationChatMessage;
use Illuminate\Foundation\Testing\LazilyRefreshDatabase;

uses(LazilyRefreshDatabase::class);

/*
|--------------------------------------------------------------------------
| mcp:calibrate-shrink-guard — replaying update_page's guard over real rewrites
|--------------------------------------------------------------------------
*/

/** One Assiste IA turn: the page text the person had, and the draft the model wrote. */
function rewriteTurn(DocumentationChat $chat, int $oldChars, int $newChars, bool $applied): void
{
    DocumentationChatMessage::factory()->for($chat, 'chat')->create([
        'role' => 'user', 'existing_content' => str_repeat('a', $oldChars),
    ]);

    DocumentationChatMessage::factory()->assistant()->for($chat, 'chat')->create([
        'draft' => str_repeat('b', $newChars), 'applied_at' => $applied ? now() : null,
    ]);
}

it('counts the applied and discarded drafts the current guard would refuse', function () {
    $chat = DocumentationChat::factory()->create();

    rewriteTurn($chat, 5000, 4500, applied: true);   // a normal edit: kept 90%
    rewriteTurn($chat, 5000, 2000, applied: true);   // a legitimate cut to 40%: a false positive
    rewriteTurn($chat, 5000, 500, applied: false);   // a gutted page nobody applied
    rewriteTurn($chat, 1500, 100, applied: true);    // too short for the guard to apply

    $this->artisan('mcp:calibrate-shrink-guard', ['--ratios' => '0.6', '--min' => '2000'])
        ->expectsOutputToContain('3 aplicados, 1 descartados')
        ->expectsTable(['encolheu para', 'página com', 'aplicados recusados', 'descartados recusados'], [
            ['→ < 60%', '> 2000', '1 (33.3%)', '1 (100%)'],
        ])
        ->assertSuccessful();
});

it('runs on an empty database', function () {
    $this->artisan('mcp:calibrate-shrink-guard')
        ->expectsOutputToContain('0 aplicados, 0 descartados')
        ->assertSuccessful();
});
