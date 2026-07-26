<?php

use App\Enums\UserRole;
use App\Jobs\GenerateFlowspecReply;
use App\Models\DocumentationPage;
use App\Models\FlowspecChat;
use App\Models\FlowspecExample;
use App\Models\Integration;
use App\Models\Solution;
use App\Models\User;
use App\Services\Flowspec\FlowspecGenerationService;
use Illuminate\Foundation\Testing\LazilyRefreshDatabase;
use Illuminate\Queue\Middleware\WithoutOverlapping;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Str;

uses(LazilyRefreshDatabase::class);

function flowspecUser(UserRole $role = UserRole::Viewer): User
{
    return User::factory()->create(['role' => $role->value]);
}

/** Minimal, valid flowSpec for assistant messages in the HTTP tests. */
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
        ->assertSee('Especialista em Integrações')
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

it('extracts solution ids from the chips payload (value/label pairs, not a flat id array)', function () {
    Queue::fake();
    $user = flowspecUser();
    $svl = Solution::factory()->create(['name' => 'SVL']);

    $this->actingAs($user)->postJson(route('flowspec.store'), [
        'message'   => 'gera um flowspec de token para o SVL',
        'solutions' => [['value' => $svl->id, 'label' => $svl->name]],
    ])->assertOk();

    $message = FlowspecChat::query()->firstOrFail()->messages()->firstOrFail();

    expect($message->meta['solution_ids'])->toBe([$svl->id]);
});

it('extracts document refs from the chips payload (page:{id}/integration:{id})', function () {
    Queue::fake();
    $user = flowspecUser();
    $page = DocumentationPage::factory()->for(Solution::factory(), 'container')->create(['documentation' => 'x']);
    $integration = Integration::factory()->create(['documentation' => 'y']);

    $this->actingAs($user)->postJson(route('flowspec.store'), [
        'message'   => 'gera um flowspec',
        'documents' => [
            ['value' => "page:{$page->id}", 'label' => $page->title],
            ['value' => "integration:{$integration->id}", 'label' => $integration->name],
        ],
    ])->assertOk();

    $message = FlowspecChat::query()->firstOrFail()->messages()->firstOrFail();

    expect($message->meta['document_refs'])->toBe([
        ['type' => 'page', 'id' => $page->id],
        ['type' => 'integration', 'id' => $integration->id],
    ]);
});

it('renders the chat page composer with the reference flowspec field', function () {
    $user = flowspecUser();
    $chat = $user->flowspecChats()->create(['title' => 'Chat']);

    $this->actingAs($user)
        ->get(route('flowspec.show', $chat))
        ->assertOk()
        // Composer renders with both the message input and the reference
        // flowSpec field (revealed from the 📎 attach menu).
        ->assertSee('id="flowspec-message-input"', false)
        ->assertSee('id="flowspec-reference-input"', false)
        ->assertSee('flowSpec de referência');
});

it('normalizes a pasted reference flowspec into meta (minified, canvas meta dropped)', function () {
    Queue::fake();
    $user = flowspecUser();

    $reference = json_encode([
        'meta'     => ['abc' => ['position' => ['x' => 200, 'y' => 0]]],
        'flowSpec' => ['disconnected-root:x' => [[
            'id'       => 'abc', 'type' => 'connector', 'name' => 'log-connector',
            'stepName' => 'Log', 'params' => ['message' => 'oi'],
        ]]],
    ], JSON_PRETTY_PRINT);

    $this->actingAs($user)->postJson(route('flowspec.store'), [
        'message'            => 'ajusta esse pipeline',
        'reference_flowspec' => $reference,
    ])->assertOk();

    $stored = FlowspecChat::query()->firstOrFail()->messages()->firstOrFail()->meta['reference_flowspec'];

    expect($stored)->toBeString()
        ->and($stored)->not->toContain("\n") // minified
        ->and(json_decode($stored, true))->not->toHaveKey('meta') // canvas positions dropped
        ->and(json_decode($stored, true))->toHaveKey('flowSpec');
});

it('persists a reference flowspec on a follow-up message too', function () {
    Queue::fake();
    $user = flowspecUser();
    $chat = $user->flowspecChats()->create(['title' => 'Chat']);

    $this->actingAs($user)->postJson(route('flowspec.messages.store', $chat), [
        'message'            => 'ajusta o timeout',
        'reference_flowspec' => '{"meta":{"a":1},"flowSpec":{"x":[]}}',
    ])->assertOk();

    $stored = $chat->messages()->firstOrFail()->meta['reference_flowspec'];

    expect(json_decode($stored, true))->toBe(['flowSpec' => ['x' => []]]);
});

it('rejects a reference flowspec that is not valid JSON', function () {
    Queue::fake();
    $user = flowspecUser();

    $response = $this->actingAs($user)->postJson(route('flowspec.store'), [
        'message'            => 'ajusta esse pipeline',
        'reference_flowspec' => '{ isto não é json',
    ])->assertStatus(422)->assertJson(['type' => 'warning']);

    expect($response->json('message'))->toContain('JSON')
        ->and(FlowspecChat::query()->count())->toBe(0);
    Queue::assertNotPushed(GenerateFlowspecReply::class);
});

it('stores a null reference_flowspec when the field is omitted', function () {
    Queue::fake();
    $user = flowspecUser();

    $this->actingAs($user)->postJson(route('flowspec.store'), ['message' => 'gera aí'])->assertOk();

    expect(FlowspecChat::query()->firstOrFail()->messages()->firstOrFail()->meta['reference_flowspec'])->toBeNull();
});

it('rejects a malformed document reference', function () {
    Queue::fake();
    $user = flowspecUser();

    $this->actingAs($user)->postJson(route('flowspec.store'), [
        'message'   => 'gera um flowspec',
        'documents' => [['value' => 'nao-existe:1', 'label' => 'x']],
    ])->assertStatus(422);
});

it('searches documentation pages and integrations for the "Documentos específicos" chips picker', function () {
    $user = flowspecUser();
    $solution = Solution::factory()->create(['name' => 'SVL']);
    $page = DocumentationPage::factory()->for($solution, 'container')->create(['title' => 'Autenticação', 'documentation' => 'x']);
    $integration = Integration::factory()->create(['name' => 'Autenticação SVL -> IAM', 'documentation' => 'y']);
    DocumentationPage::factory()->for($solution, 'container')->create(['title' => 'Sem relação', 'documentation' => 'z']);

    $response = $this->actingAs($user)
        ->getJson(route('flowspec.documents.search', ['q' => 'Autenticação']))
        ->assertOk();

    expect($response->json('results.*.id'))->toBe(["page:{$page->id}", "integration:{$integration->id}"]);
});

it('finds an integration match even when no documentation page matches the term', function () {
    $user = flowspecUser();
    Integration::factory()->create(['name' => 'Access One -> SVL -> SAP | Gestão de Atendentes', 'documentation' => 'y']);

    $response = $this->actingAs($user)
        ->getJson(route('flowspec.documents.search', ['q' => 'Gestão de Atendentes']))
        ->assertOk();

    expect($response->json('results.*.name'))->toBe(['Access One -> SVL -> SAP | Gestão de Atendentes']);
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

it('rejects a new message while a reply is still being generated', function () {
    // The composer sits outside the thread slot and stays enabled during the
    // "generating…" state — it's the server that guarantees one pending turn at a time.
    Queue::fake();
    $user = flowspecUser();
    $chat = $user->flowspecChats()->create(['title' => 'Chat']);
    $chat->messages()->create(['role' => 'user', 'content' => 'gera aí']);

    $response = $this->actingAs($user)
        ->postJson(route('flowspec.messages.store', $chat), ['message' => 'mais uma'])
        ->assertStatus(422)
        ->assertJson(['type' => 'warning']);

    expect($response->json('message'))->toContain('Aguarde a resposta atual')
        ->and($chat->messages()->count())->toBe(1);
    Queue::assertNotPushed(GenerateFlowspecReply::class);
});

it('accepts a new message once a stalled reply is past the generation window', function () {
    // A worker killed before it fires handle()/failed() never creates the
    // assistant reply, so the last message stays role='user'. Past
    // REPLY_STALL_SECONDS the generation is treated as dead — the composer must
    // recover instead of locking the chat out forever.
    Queue::fake();
    $user = flowspecUser();
    $chat = $user->flowspecChats()->create(['title' => 'Chat']);
    $chat->messages()->create(['role' => 'user', 'content' => 'gera aí']);

    $this->travel(FlowspecChat::REPLY_STALL_SECONDS + 60)->seconds();

    $this->actingAs($user)
        ->postJson(route('flowspec.messages.store', $chat), ['message' => 'mais uma'])
        ->assertOk()
        ->assertJsonStructure(['updatableSlots']);

    expect($chat->messages()->count())->toBe(2);
    Queue::assertPushed(GenerateFlowspecReply::class);
});

it('writes no reply for a turn a later message has superseded (resurrected or duplicate job)', function () {
    // Covers both a job the queue resurrects after a hard worker kill (once the
    // stall guard reopened the composer and the user sent again) and a
    // double-submit race that slipped two messages past the store() guard.
    $user = flowspecUser();
    $chat = $user->flowspecChats()->create(['title' => 'Chat']);
    $stale = $chat->messages()->create(['role' => 'user', 'content' => 'primeira']);
    $chat->messages()->create(['role' => 'user', 'content' => 'segunda — supersede']);

    // isSuperseded() must short-circuit before generate() is ever called.
    $service = Mockery::mock(FlowspecGenerationService::class);
    $service->shouldNotReceive('generate');

    (new GenerateFlowspecReply($stale))->handle($service);

    expect($chat->messages()->where('role', 'assistant')->count())->toBe(0)
        ->and($chat->messages()->count())->toBe(2);
});

it('writes no failure reply for a superseded turn', function () {
    $user = flowspecUser();
    $chat = $user->flowspecChats()->create(['title' => 'Chat']);
    $stale = $chat->messages()->create(['role' => 'user', 'content' => 'primeira']);
    $chat->messages()->create(['role' => 'assistant', 'content' => 'resposta do turno atual']);

    (new GenerateFlowspecReply($stale))->failed(new RuntimeException('api down'));

    // The current turn already has its reply — the stale job's failed() adds nothing.
    expect($chat->messages()->where('role', 'assistant')->count())->toBe(1);
});

it('persists only the exception type on failure, never the raw provider message', function () {
    $user = flowspecUser();
    $chat = $user->flowspecChats()->create(['title' => 'Chat']);
    $stale = $chat->messages()->create(['role' => 'user', 'content' => 'gera aí']);

    (new GenerateFlowspecReply($stale))->failed(new RuntimeException('POST https://api.example.com key=sk-secret-123'));

    $reply = $chat->messages()->where('role', 'assistant')->firstOrFail();

    expect($reply->meta['error_type'])->toBe(RuntimeException::class)
        ->and($reply->meta)->not->toHaveKey('error')
        ->and(json_encode($reply->meta))->not->toContain('sk-secret-123');
});

it('rejects a context selection larger than the allowed maximum', function () {
    Queue::fake();
    $user = flowspecUser();
    // 21 real solutions so only the array `max` rule can fail (each value still
    // passes exists:solutions,id).
    $solutions = Solution::factory()->count(21)->create()
        ->map(fn (Solution $s) => ['value' => $s->id, 'label' => $s->name])
        ->all();

    $response = $this->actingAs($user)
        ->postJson(route('flowspec.store'), ['message' => 'gera aí', 'solutions' => $solutions])
        ->assertStatus(422)
        ->assertJson(['type' => 'warning']);

    expect($response->json('message'))->toContain('20')
        ->and(FlowspecChat::query()->count())->toBe(0);
    Queue::assertNotPushed(GenerateFlowspecReply::class);
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

it('adds a hand-entered example to the corpus (admin)', function () {
    $admin = flowspecUser(UserRole::Admin);

    $this->actingAs($admin)
        ->postJson(route('flowspec.examples.store'), [
            'name'        => 'Resposta simples',
            'description' => 'Gera uma resposta ok.',
            'tags'        => ['rest'],
            'flow_spec'   => json_encode(assistantFlowspec()),
        ])
        ->assertOk()
        ->assertJson(['type' => 'success']);

    $example = FlowspecExample::query()->where('slug', 'resposta-simples')->firstOrFail();

    // connectors are always re-derived from the flowSpec, never trusted as input.
    expect($example->source)->toBe('manual')
        ->and($example->is_active)->toBeTrue()
        ->and($example->connectors)->toBe(['json-generator-connector'])
        ->and($example->tags)->toBe(['rest']);
});

it('rejects an example whose flowSpec is not a valid {meta, flowSpec} document', function () {
    $admin = flowspecUser(UserRole::Admin);

    $response = $this->actingAs($admin)
        ->postJson(route('flowspec.examples.store'), [
            'name'        => 'Quebrado',
            'description' => 'Y',
            'tags'        => ['rest'],
            'flow_spec'   => '{ not valid json',
        ])
        ->assertStatus(422)
        ->assertJson(['type' => 'warning']);

    expect($response->json('message'))->toContain('JSON válido')
        ->and(FlowspecExample::query()->count())->toBe(0);
});

it('rejects an example carrying a literal credential', function () {
    $admin = flowspecUser(UserRole::Admin);
    $leaky = assistantFlowspec();
    $rootKey = array_key_first($leaky['flowSpec']);
    $leaky['flowSpec'][$rootKey][0]['params']['json'] = '{"x-api-key": "chave-literal-123"}';

    $response = $this->actingAs($admin)
        ->postJson(route('flowspec.examples.store'), [
            'name'        => 'Vazado',
            'description' => 'Y',
            'tags'        => ['rest'],
            'flow_spec'   => json_encode($leaky),
        ])
        ->assertStatus(422)
        ->assertJson(['type' => 'warning']);

    expect($response->json('message'))->toContain('credenciais literais')
        ->and(FlowspecExample::query()->count())->toBe(0);
});

it('generates a distinct slug when two examples share the same name', function () {
    $admin = flowspecUser(UserRole::Admin);
    $payload = fn () => ['name' => 'Mesmo nome', 'description' => 'Y', 'tags' => ['rest'], 'flow_spec' => json_encode(assistantFlowspec())];

    $this->actingAs($admin)->postJson(route('flowspec.examples.store'), $payload())->assertOk();
    $this->actingAs($admin)->postJson(route('flowspec.examples.store'), $payload())->assertOk();

    $slugs = FlowspecExample::query()->pluck('slug');

    expect($slugs)->toHaveCount(2)
        ->and($slugs->unique())->toHaveCount(2)
        ->and($slugs)->toContain('mesmo-nome');
});

it('updates an example, re-deriving connectors and toggling it inactive', function () {
    $admin = flowspecUser(UserRole::Admin);
    $example = FlowspecExample::factory()->create(['name' => 'Antigo', 'slug' => 'antigo', 'tags' => ['rest'], 'is_active' => true]);

    $this->actingAs($admin)
        ->patchJson(route('flowspec.examples.update', $example), [
            'name'        => 'Novo nome',
            'description' => 'Atualizado.',
            'tags'        => ['rest', 'token'],
            'flow_spec'   => json_encode(assistantFlowspec()),
            // is_active omitted → treated as false (toggle unchecked).
        ])
        ->assertOk()
        ->assertJson(['type' => 'success']);

    $example->refresh();

    expect($example->name)->toBe('Novo nome')
        ->and($example->slug)->toBe('antigo') // slug is a stable key — not renamed
        ->and($example->tags)->toBe(['rest', 'token'])
        ->and($example->connectors)->toBe(['json-generator-connector'])
        ->and($example->is_active)->toBeFalse();
});

it('deletes an example from the corpus', function () {
    $admin = flowspecUser(UserRole::Admin);
    $example = FlowspecExample::factory()->create();

    $this->actingAs($admin)
        ->deleteJson(route('flowspec.examples.destroy', $example))
        ->assertOk()
        ->assertJson(['type' => 'success']);

    $this->assertModelMissing($example);
});

it('renders the corpus management modal for an admin', function () {
    FlowspecExample::factory()->count(2)->create();
    $admin = flowspecUser(UserRole::Admin);

    $response = $this->actingAs($admin)->getJson(route('flowspec.examples.index'))->assertOk();

    expect($response->json('content'))
        ->toContain('Gerenciar referências')
        ->toContain('Novo exemplo')
        ->toContain('flowspec-example-list-slot');
});

it('forbids non-admins from managing the corpus', function () {
    $viewer = flowspecUser();
    $example = FlowspecExample::factory()->create();
    $payload = ['name' => 'X', 'description' => 'Y', 'tags' => ['rest'], 'flow_spec' => json_encode(assistantFlowspec())];

    $this->actingAs($viewer)->getJson(route('flowspec.examples.index'))->assertForbidden();
    $this->actingAs($viewer)->postJson(route('flowspec.examples.store'), $payload)->assertForbidden();
    $this->actingAs($viewer)->patchJson(route('flowspec.examples.update', $example), $payload)->assertForbidden();
    $this->actingAs($viewer)->deleteJson(route('flowspec.examples.destroy', $example))->assertForbidden();
});
