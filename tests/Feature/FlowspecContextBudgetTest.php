<?php

use App\Models\DocumentationPage;
use App\Models\FlowspecAttachment;
use App\Models\FlowspecChat;
use App\Models\FlowspecMessage;
use App\Models\Solution;
use App\Models\User;
use App\Services\Flowspec\FlowspecContextBudget;
use App\Services\Flowspec\FlowspecContextResolver;
use App\Services\Flowspec\FlowspecPromptBuilder;
use App\Support\Context\TokenEstimator;
use Illuminate\Foundation\Testing\LazilyRefreshDatabase;
use Illuminate\Support\Facades\Queue;

uses(LazilyRefreshDatabase::class);

/*
|--------------------------------------------------------------------------
| The context meter
|--------------------------------------------------------------------------
|
| The conversation's context is re-sent on EVERY turn, which is how a thread
| with a few large documents becomes runaway spend. These are the tests on the
| guard: the number the composer shows, and the refusal that makes it a real
| ceiling rather than a decoration.
|
*/

it('measures an attached document live, so editing it moves the meter', function () {
    $chat = FlowspecChat::factory()->create();
    $page = DocumentationPage::factory()->for(Solution::factory()->create(), 'container')
        ->create(['documentation' => str_repeat('a', 3500)]);

    attachPage($chat, $page);

    $before = app(FlowspecContextBudget::class)->for($chat);
    expect($before->lines['Documentos do inventário'])->toBe(1000); // 3500 chars / 3.5

    $page->update(['documentation' => str_repeat('a', 7000)]);

    // No cached size to go stale: the row holds a reference, not a copy.
    expect(app(FlowspecContextBudget::class)->for($chat)->lines['Documentos do inventário'])->toBe(2000);
});

it('measures a file or paste from the estimate stored when it was ingested', function () {
    $chat = FlowspecChat::factory()->create();

    FlowspecAttachment::factory()->for($chat, 'chat')->create(['token_estimate' => 1200]);
    FlowspecAttachment::factory()->for($chat, 'chat')->nativeAttachment()->create(['token_estimate' => 800]);

    expect(app(FlowspecContextBudget::class)->for($chat)->lines['Arquivos e textos'])->toBe(2000);
});

it('counts the conversation history, embedding only the most recent flowSpec', function () {
    $chat = FlowspecChat::factory()->create();

    FlowspecMessage::factory()->create(['flowspec_chat_id' => $chat->id, 'role' => 'user', 'content' => str_repeat('b', 350)]);
    FlowspecMessage::factory()->assistant()->create([
        'flowspec_chat_id' => $chat->id,
        'content'          => '',
        'flow_spec'        => ['meta' => [], 'flowSpec' => ['old' => []]],
    ]);
    FlowspecMessage::factory()->assistant()->create([
        'flowspec_chat_id' => $chat->id,
        'content'          => '',
        'flow_spec'        => ['meta' => [], 'flowSpec' => ['new' => []]],
    ]);

    $latestJson = (string) json_encode(['meta' => [], 'flowSpec' => ['new' => []]], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);

    // Only the newest document is embedded in full by
    // FlowspecPromptBuilder::historySection(), so only it may be counted here —
    // the older one collapses to a placeholder there.
    expect(app(FlowspecContextBudget::class)->for($chat)->history)
        ->toBe(TokenEstimator::forChars(350 + mb_strlen($latestJson)));
});

it('reserves room for the conversation, so attachments can never fill the whole window', function () {
    config()->set('services.flowspec.context_limit_tokens', 100000);
    config()->set('services.flowspec.history_reserve_tokens', 40000);

    $usage = app(FlowspecContextBudget::class)->for(FlowspecChat::factory()->create());

    expect($usage->limit)->toBe(100000)
        ->and($usage->attachLimit)->toBe(60000)
        // The fixed prompt (rules + catalog + corpus) eats into what's
        // attachable, so this is below attachLimit but comfortably positive.
        ->and($usage->attachableTokens())->toBeGreaterThan(0)
        ->and($usage->attachableTokens())->toBeLessThan(60000);
});

it('never reports a negative ceiling when the reserve is misconfigured above the limit', function () {
    config()->set('services.flowspec.context_limit_tokens', 10000);
    config()->set('services.flowspec.history_reserve_tokens', 50000);

    $usage = app(FlowspecContextBudget::class)->for(FlowspecChat::factory()->create());

    expect($usage->attachLimit)->toBe(0)
        ->and($usage->attachableTokens())->toBe(0)
        ->and($usage->attachmentsFull())->toBeTrue();
});

it('caps the meter at 100% instead of overflowing the bar', function () {
    config()->set('services.flowspec.context_limit_tokens', 100);

    $chat = FlowspecChat::factory()->create();
    FlowspecAttachment::factory()->for($chat, 'chat')->create(['token_estimate' => 9999]);

    $usage = app(FlowspecContextBudget::class)->for($chat);

    expect($usage->percent())->toBe(100)
        ->and($usage->nearLimit())->toBeTrue();
});

/*
|--------------------------------------------------------------------------
| The refusal
|--------------------------------------------------------------------------
*/

it('refuses a document that would not fit, without creating anything', function () {
    config()->set('services.flowspec.context_limit_tokens', 5000);
    config()->set('services.flowspec.history_reserve_tokens', 1000);

    $user = User::factory()->create();
    $chat = FlowspecChat::factory()->for($user)->create();
    $huge = DocumentationPage::factory()->for(Solution::factory()->create(), 'container')
        ->create(['title' => 'Manual inteiro', 'documentation' => str_repeat('x', 200000)]);

    $response = $this->actingAs($user)
        ->postJson(route('flowspec.attachments.store', $chat), ['documents' => ["page:{$huge->id}"]])
        ->assertStatus(422)
        ->assertJson(['type' => 'warning']);

    expect($response->json('message'))->toContain('limite de contexto')
        ->and($chat->attachments()->count())->toBe(0);
});

it('refuses a new conversation whose staged context would not fit', function () {
    Queue::fake();
    config()->set('services.flowspec.context_limit_tokens', 5000);
    config()->set('services.flowspec.history_reserve_tokens', 1000);

    $user = User::factory()->create();

    $this->actingAs($user)->postJson(route('flowspec.store'), [
        'message' => 'gera o pipeline',
        'texts'   => [['content' => str_repeat('y', 190000), 'label' => 'Especificação inteira']],
    ])->assertStatus(422);

    expect(FlowspecChat::query()->count())->toBe(0);
    Queue::assertNotPushed(App\Jobs\GenerateFlowspecReply::class);
});

it('still accepts an attachment that fits in what is left', function () {
    config()->set('services.flowspec.context_limit_tokens', 500000);

    $user = User::factory()->create();
    $chat = FlowspecChat::factory()->for($user)->create();
    $page = DocumentationPage::factory()->for(Solution::factory()->create(), 'container')
        ->create(['documentation' => str_repeat('x', 5000)]);

    $this->actingAs($user)
        ->postJson(route('flowspec.attachments.store', $chat), ['documents' => ["page:{$page->id}"]])
        ->assertOk();

    expect($chat->attachments()->count())->toBe(1);
});

/*
|--------------------------------------------------------------------------
| History trims itself — the one thing that is never refused
|--------------------------------------------------------------------------
*/

it('drops the oldest turns to fit, keeping the newest and saying how many went', function () {
    $chat = FlowspecChat::factory()->create();

    $history = collect(range(1, 6))->map(fn (int $i) => FlowspecMessage::factory()->create([
        'flowspec_chat_id' => $chat->id,
        'role'             => 'user',
        'content'          => "mensagem {$i} " . str_repeat('z', 350),
    ]));

    // Room for roughly two of the six blocks.
    $built = app(FlowspecPromptBuilder::class)->userPrompt(emptyFlowspecContext(), 'próximo pedido', $history, 250);

    expect($built->trimmedHistoryTurns)->toBeGreaterThan(0)
        ->and($built->text)->toContain('mensagem 6')
        ->and($built->text)->not->toContain('mensagem 1')
        ->and($built->text)->toContain('mais antiga(s) desta conversa foram omitidas');
});

it('keeps the newest turn even when it alone exceeds the allowance', function () {
    $chat = FlowspecChat::factory()->create();

    $history = collect([FlowspecMessage::factory()->create([
        'flowspec_chat_id' => $chat->id,
        'role'             => 'user',
        'content'          => 'pedido enorme ' . str_repeat('z', 10000),
    ])]);

    // Answering with no history at all is worse than one turn over budget, and
    // refusing outright would lock someone out of their own conversation.
    $built = app(FlowspecPromptBuilder::class)->userPrompt(emptyFlowspecContext(), 'e agora?', $history, 10);

    expect($built->text)->toContain('pedido enorme')
        ->and($built->trimmedHistoryTurns)->toBe(0);
});

it('trims nothing when no allowance is given', function () {
    $chat = FlowspecChat::factory()->create();
    $history = collect(range(1, 4))->map(fn (int $i) => FlowspecMessage::factory()->create([
        'flowspec_chat_id' => $chat->id,
        'role'             => 'user',
        'content'          => "mensagem {$i}",
    ]));

    $built = app(FlowspecPromptBuilder::class)->userPrompt(emptyFlowspecContext(), 'pedido', $history);

    expect($built->trimmedHistoryTurns)->toBe(0)
        ->and($built->text)->toContain('mensagem 1');
});

/*
|--------------------------------------------------------------------------
| What the composer actually shows
|--------------------------------------------------------------------------
*/

it('renders the meter and the attached items in the composer', function () {
    $user = User::factory()->create();
    $chat = FlowspecChat::factory()->for($user)->create();
    $page = DocumentationPage::factory()->for(Solution::factory()->create(['name' => 'SVL']), 'container')
        ->create(['title' => 'Contrato', 'documentation' => str_repeat('x', 3500)]);

    attachPage($chat, $page);

    $this->actingAs($user)->get(route('flowspec.show', $chat))
        ->assertOk()
        ->assertSee('Contrato')
        ->assertSee('Contexto', escape: false)
        ->assertSee('Documentos do inventário')
        ->assertSee('data-ak-fs-detach', escape: false);
});

it('tells the user the context is full, on the screen', function () {
    config()->set('services.flowspec.context_limit_tokens', 10);

    $user = User::factory()->create();
    $chat = FlowspecChat::factory()->for($user)->create();

    $this->actingAs($user)->get(route('flowspec.show', $chat))
        ->assertOk()
        ->assertSee('cheio');
});

it('reports the estimated context and any trim in the message meta', function () {
    $chat = FlowspecChat::factory()->create();
    $page = DocumentationPage::factory()->for(Solution::factory()->create(), 'container')
        ->create(['documentation' => str_repeat('x', 3500)]);
    attachPage($chat, $page);

    $context = app(FlowspecContextResolver::class)->resolve($chat, 'gera');

    expect($context->toMeta())
        ->toHaveKeys(['pages', 'integration_docs', 'text_docs', 'reference_flowspecs', 'attached_files', 'omitted_attachments']);
});

/*
|--------------------------------------------------------------------------
| Malformed context input
|--------------------------------------------------------------------------
|
| The count guard runs in `withValidator`'s `after` callback, and Laravel fires
| those even when the rules above them already failed — so it reads RAW input,
| not a validated set. Counting it with `count()` therefore died on any scalar
| where an array was declared: a 500 out of a request the validator had already
| written a 422 for.
|
*/

it('answers a scalar where an array was declared with a 422, never a 500', function () {
    $user = User::factory()->create();
    $chat = FlowspecChat::factory()->for($user)->create();

    foreach ([['documents' => 'page:1'], ['texts' => 'não é array'], ['files' => 'nem isso']] as $payload) {
        $this->actingAs($user)
            ->postJson(route('flowspec.attachments.store', $chat), $payload)
            ->assertStatus(422)
            ->assertJson(['type' => 'warning']);
    }

    expect($chat->attachments()->count())->toBe(0);
});

it('does the same on the endpoint that opens a conversation', function () {
    Queue::fake();
    $user = User::factory()->create();

    $this->actingAs($user)
        ->postJson(route('flowspec.store'), ['message' => 'gera aí', 'documents' => 'page:1'])
        ->assertStatus(422);

    expect(FlowspecChat::query()->count())->toBe(0);
    Queue::assertNotPushed(App\Jobs\GenerateFlowspecReply::class);
});

it('still counts both shapes of the same input against the attachment cap', function () {
    // `text`/`file` (one at a time) and `texts[]`/`files[]` (the staged new-chat
    // composer) are the same thing arriving two ways — the cap has to see both,
    // which is why the count goes through the same parsers the controller
    // attaches with instead of reading the raw keys.
    config()->set('services.flowspec.max_attachments', 2);

    $user = User::factory()->create();
    $chat = FlowspecChat::factory()->for($user)->create();

    $response = $this->actingAs($user)->postJson(route('flowspec.attachments.store', $chat), [
        'text'  => 'primeiro texto',
        'texts' => [['content' => 'segundo texto'], ['content' => 'terceiro texto']],
    ])->assertStatus(422);

    expect($response->json('message'))->toContain('máximo de 2 itens')
        ->and($chat->attachments()->count())->toBe(0);
});
