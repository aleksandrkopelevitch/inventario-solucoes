<?php

use App\Enums\UserRole;
use App\Jobs\GenerateFlowspecReply;
use App\Models\FlowspecChat;
use App\Models\FlowspecExample;
use App\Models\Integration;
use App\Models\User;
use Illuminate\Foundation\Testing\LazilyRefreshDatabase;
use Illuminate\Queue\Middleware\WithoutOverlapping;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Str;

uses(LazilyRefreshDatabase::class);

function flowspecUser(UserRole $role = UserRole::Viewer): User
{
    return User::factory()->create(['role' => $role->value]);
}

/** flowSpec mínimo e válido para mensagens de assistant nos testes HTTP. */
function assistantFlowspec(): array
{
    $id = (string) Str::uuid();

    return [
        'meta'     => [$id => ['position' => ['x' => 200, 'y' => 0]]],
        'flowSpec' => ['disconnected-root:' . Str::uuid() => [[
            'id'       => $id, 'type' => 'connector', 'name' => 'json-generator-connector',
            'stepName' => 'Response', 'params' => ['json' => '{"ok": true}', 'failOnError' => false],
        ]]],
    ];
}

it('renders the chat index with previous conversations', function () {
    $user = flowspecUser();
    $user->flowspecChats()->create(['title' => 'Cache de token SVL']);

    $this->actingAs($user)
        ->get(route('flowspec.index'))
        ->assertOk()
        ->assertSee('Gerador de flowSpec')
        ->assertSee('Cache de token SVL');
});

it('creates a chat, persists the user message and dispatches the generation job', function () {
    Queue::fake();
    $user = flowspecUser();

    $response = $this->actingAs($user)->postJson(route('flowspec.store'), [
        'message'   => 'gera um flowspec de token para o SVL',
        'solutions' => [],
    ]);

    $chat = FlowspecChat::query()->firstOrFail();

    $response->assertOk()->assertJson(['redirect' => route('flowspec.show', $chat)]);

    expect($chat->messages()->count())->toBe(1)
        ->and($chat->messages()->firstOrFail()->role)->toBe('user');

    Queue::assertPushed(GenerateFlowspecReply::class);
});

it('appends a message to an existing chat and dispatches the job', function () {
    Queue::fake();
    $user = flowspecUser();
    $chat = $user->flowspecChats()->create(['title' => 'Chat']);

    $this->actingAs($user)
        ->postJson(route('flowspec.messages.store', $chat), ['message' => 'ajusta o timeout'])
        ->assertOk()
        ->assertJsonStructure(['updatableSlots']);

    expect($chat->messages()->count())->toBe(1);
    Queue::assertPushed(GenerateFlowspecReply::class);
});

it('reports pending status while the last message is from the user', function () {
    $user = flowspecUser();
    $chat = $user->flowspecChats()->create(['title' => 'Chat']);
    $chat->messages()->create(['role' => 'user', 'content' => 'gera aí']);

    $this->actingAs($user)
        ->getJson(route('flowspec.status', $chat))
        ->assertOk()
        ->assertJson(['pending' => true]);

    $chat->messages()->create(['role' => 'assistant', 'content' => 'pronto', 'flow_spec' => assistantFlowspec(), 'meta' => ['validated' => true]]);

    $this->actingAs($user)
        ->getJson(route('flowspec.status', $chat))
        ->assertOk()
        ->assertJson(['pending' => false]);
});

it('skips building the thread slot while pending, to avoid rendering it on every poll tick', function () {
    $user = flowspecUser();
    $chat = $user->flowspecChats()->create(['title' => 'Chat']);
    $chat->messages()->create(['role' => 'user', 'content' => 'gera aí']);

    $pendingResponse = $this->actingAs($user)->getJson(route('flowspec.status', $chat))->assertOk();
    expect($pendingResponse->json('updatableSlots'))->toBe([]);

    $chat->messages()->create(['role' => 'assistant', 'content' => 'pronto', 'flow_spec' => assistantFlowspec(), 'meta' => ['validated' => true]]);

    $readyResponse = $this->actingAs($user)->getJson(route('flowspec.status', $chat))->assertOk();
    expect($readyResponse->json('updatableSlots.0.id'))->toBe('flowspec-thread-slot');
});

it('declares WithoutOverlapping middleware keyed by the chat, so concurrent messages serialize', function () {
    $user = flowspecUser();
    $chat = $user->flowspecChats()->create(['title' => 'Chat']);
    $message = $chat->messages()->create(['role' => 'user', 'content' => 'gera aí']);

    $job = new GenerateFlowspecReply($message);
    $middleware = $job->middleware();

    expect($middleware)->toHaveCount(1)
        ->and($middleware[0])->toBeInstanceOf(WithoutOverlapping::class)
        ->and((int) $middleware[0]->key)->toBe($chat->id);
});

it('blocks another user from viewing or messaging a chat', function () {
    $owner = flowspecUser();
    $chat = $owner->flowspecChats()->create(['title' => 'Privado']);

    $intruder = flowspecUser();

    $this->actingAs($intruder)->get(route('flowspec.show', $chat))->assertForbidden();
    $this->actingAs($intruder)->postJson(route('flowspec.messages.store', $chat), ['message' => 'oi'])->assertForbidden();
});

it('attaches a generated flowspec to an integration (admin)', function () {
    $admin = flowspecUser(UserRole::Admin);
    $chat = $admin->flowspecChats()->create(['title' => 'Chat']);
    $message = $chat->messages()->create(['role' => 'assistant', 'content' => 'pronto', 'flow_spec' => assistantFlowspec(), 'meta' => ['validated' => true]]);
    $integration = Integration::factory()->create();

    $this->actingAs($admin)
        ->postJson(route('flowspec.messages.attach', [$chat, $message]), ['integration_id' => $integration->id])
        ->assertOk()
        ->assertJson(['type' => 'success']);

    $integration->refresh();

    expect($integration->generated_flowspec)->not->toBeNull()
        ->and($integration->flowspec_status)->toBe('validated')
        ->and($integration->flowspec_generated_at)->not->toBeNull()
        ->and($chat->refresh()->integration_id)->toBe($integration->id);
});

it('rejects attaching a message that carries no flowspec', function () {
    $admin = flowspecUser(UserRole::Admin);
    $chat = $admin->flowspecChats()->create(['title' => 'Chat']);
    $message = $chat->messages()->create(['role' => 'assistant', 'content' => 'sem json']);
    $integration = Integration::factory()->create();

    $response = $this->actingAs($admin)
        ->postJson(route('flowspec.messages.attach', [$chat, $message]), ['integration_id' => $integration->id])
        ->assertStatus(422)
        ->assertJson(['type' => 'warning']);

    expect($response->json('message'))->toContain('não carrega um flowSpec');
});

it('promotes a validated flowspec to a corpus example (admin)', function () {
    $admin = flowspecUser(UserRole::Admin);
    $chat = $admin->flowspecChats()->create(['title' => 'Chat']);
    $message = $chat->messages()->create(['role' => 'assistant', 'content' => 'pronto', 'flow_spec' => assistantFlowspec(), 'meta' => ['validated' => true]]);

    $this->actingAs($admin)
        ->postJson(route('flowspec.messages.promote', [$chat, $message]), [
            'name'        => 'Resposta simples',
            'description' => 'Gera uma resposta ok.',
            'tags'        => ['rest'],
        ])
        ->assertOk()
        ->assertJson(['type' => 'success']);

    $example = FlowspecExample::query()->where('slug', 'resposta-simples')->firstOrFail();

    expect($example->source)->toBe('chat')
        ->and($example->connectors)->toBe(['json-generator-connector']);
});

it('blocks promotion for non-admins and for leaky flowspecs', function () {
    $viewer = flowspecUser();
    $chat = $viewer->flowspecChats()->create(['title' => 'Chat']);
    $message = $chat->messages()->create(['role' => 'assistant', 'content' => 'pronto', 'flow_spec' => assistantFlowspec(), 'meta' => ['validated' => true]]);

    $this->actingAs($viewer)
        ->postJson(route('flowspec.messages.promote', [$chat, $message]), ['name' => 'X', 'description' => 'Y', 'tags' => ['rest']])
        ->assertForbidden();

    $admin = flowspecUser(UserRole::Admin);
    $leakyChat = $admin->flowspecChats()->create(['title' => 'Chat vazado']);
    $leaky = assistantFlowspec();
    $rootKey = array_key_first($leaky['flowSpec']);
    $leaky['flowSpec'][$rootKey][0]['params']['json'] = '{"x-api-key": "chave-literal-123"}';
    $leakyMessage = $leakyChat->messages()->create(['role' => 'assistant', 'content' => 'pronto', 'flow_spec' => $leaky, 'meta' => ['validated' => true]]);

    $response = $this->actingAs($admin)
        ->postJson(route('flowspec.messages.promote', [$leakyChat, $leakyMessage]), ['name' => 'Vazado', 'description' => 'Y', 'tags' => ['rest']])
        ->assertStatus(422)
        ->assertJson(['type' => 'warning']);

    expect($response->json('message'))->toContain('credencial literal');
});

it('404s when the message belongs to another chat (scoped binding)', function () {
    $admin = flowspecUser(UserRole::Admin);
    $chat = $admin->flowspecChats()->create(['title' => 'A']);
    $otherChat = $admin->flowspecChats()->create(['title' => 'B']);
    $foreign = $otherChat->messages()->create(['role' => 'assistant', 'content' => 'x', 'flow_spec' => assistantFlowspec()]);
    $integration = Integration::factory()->create();

    $this->actingAs($admin)
        ->postJson(route('flowspec.messages.attach', [$chat, $foreign]), ['integration_id' => $integration->id])
        ->assertNotFound();
});
