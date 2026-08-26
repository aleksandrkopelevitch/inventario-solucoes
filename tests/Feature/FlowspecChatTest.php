<?php

use App\Enums\UserRole;
use App\Jobs\GenerateFlowspecReply;
use App\Models\DocumentationPage;
use App\Models\FlowspecChat;
use App\Models\FlowspecExample;
use App\Models\FlowspecGuideline;
use App\Models\Diagram;
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
        'message' => 'gera um flowspec de token para o SVL',
    ]);

    $chat = FlowspecChat::query()->firstOrFail();

    $response->assertOk()->assertJson(['redirect' => route('flowspec.show', $chat)]);

    expect($chat->messages()->count())->toBe(1)
        ->and($chat->messages()->firstOrFail()->role)->toBe('user');

    Queue::assertPushed(GenerateFlowspecReply::class);
});

it('rejects a malformed document reference', function () {
    Queue::fake();
    $user = flowspecUser();

    $this->actingAs($user)->postJson(route('flowspec.store'), [
        'message'   => 'gera um flowspec',
        'documents' => ['nao-existe:1'],
    ])->assertStatus(422);
});

it('searches documentation pages for the picker', function () {
    $user = flowspecUser();
    $solution = Solution::factory()->create(['name' => 'SVL']);
    $page = DocumentationPage::factory()->for($solution, 'container')->create(['title' => 'Autenticação', 'documentation' => 'x']);
    DocumentationPage::factory()->for($solution, 'container')->create(['title' => 'Sem relação', 'documentation' => 'z']);

    $response = $this->actingAs($user)
        ->getJson(route('flowspec.documents.search', ['q' => 'Autenticação']))
        ->assertOk();

    expect($response->json('results.*.id'))->toBe(["page:{$page->id}"]);
});

it('rejects a diagram reference — a drawing carries no text to attach', function () {
    $user = flowspecUser();
    $diagram = Diagram::factory()->create(['name' => 'IAM -> SVL']);

    $this->actingAs($user)->postJson(route('flowspec.store'), [
        'message'   => 'gera um flowspec',
        'documents' => ["diagram:{$diagram->id}"],
    ])->assertStatus(422);
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

it('refuses more context items than the per-conversation maximum', function () {
    Queue::fake();
    config()->set('services.flowspec.max_attachments', 3);
    $user = flowspecUser();

    $solution = Solution::factory()->create();
    $pages = DocumentationPage::factory()->count(4)->for($solution, 'container')
        ->create(['documentation' => 'conteudo'])
        ->map(fn (DocumentationPage $page) => "page:{$page->id}")
        ->all();

    $response = $this->actingAs($user)
        ->postJson(route('flowspec.store'), ['message' => 'gera aí', 'documents' => $pages])
        ->assertStatus(422)
        ->assertJson(['type' => 'warning']);

    expect($response->json('message'))->toContain('3')
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

it('adds a hand-entered guideline document (admin)', function () {
    $admin = flowspecUser(UserRole::Admin);

    $this->actingAs($admin)
        ->postJson(route('flowspec.guidelines.store'), [
            'title'   => 'Boas práticas Digibee',
            'content' => 'Prefira sempre reaproveitar conectores existentes antes de propor um novo.',
        ])
        ->assertOk()
        ->assertJson(['type' => 'success']);

    $guideline = FlowspecGuideline::query()->where('slug', 'boas-praticas-digibee')->firstOrFail();

    expect($guideline->source)->toBe('manual')
        ->and($guideline->is_active)->toBeTrue();
});

it('rejects a guideline carrying a literal credential', function () {
    $admin = flowspecUser(UserRole::Admin);

    $response = $this->actingAs($admin)
        ->postJson(route('flowspec.guidelines.store'), [
            'title'   => 'Vazado',
            'content' => 'Exemplo de header: {"x-api-key":"chave-literal-123"}',
        ])
        ->assertStatus(422)
        ->assertJson(['type' => 'warning']);

    expect($response->json('message'))->toContain('credenciais literais')
        ->and(FlowspecGuideline::query()->count())->toBe(0);
});

it('generates a distinct slug when two guidelines share the same title', function () {
    $admin = flowspecUser(UserRole::Admin);
    $payload = fn () => ['title' => 'Mesmo título', 'content' => 'Conteúdo qualquer.'];

    $this->actingAs($admin)->postJson(route('flowspec.guidelines.store'), $payload())->assertOk();
    $this->actingAs($admin)->postJson(route('flowspec.guidelines.store'), $payload())->assertOk();

    $slugs = FlowspecGuideline::query()->pluck('slug');

    expect($slugs)->toHaveCount(2)
        ->and($slugs->unique())->toHaveCount(2)
        ->and($slugs)->toContain('mesmo-titulo');
});

it('updates a guideline, keeping its slug stable and toggling it inactive', function () {
    $admin = flowspecUser(UserRole::Admin);
    $guideline = FlowspecGuideline::factory()->create(['title' => 'Antiga', 'slug' => 'antiga', 'is_active' => true]);

    $this->actingAs($admin)
        ->patchJson(route('flowspec.guidelines.update', $guideline), [
            'title'   => 'Novo título',
            'content' => 'Conteúdo atualizado.',
            // is_active omitted → treated as false (toggle unchecked).
        ])
        ->assertOk()
        ->assertJson(['type' => 'success']);

    $guideline->refresh();

    expect($guideline->title)->toBe('Novo título')
        ->and($guideline->slug)->toBe('antiga') // slug is a stable key — not renamed
        ->and($guideline->content)->toBe('Conteúdo atualizado.')
        ->and($guideline->is_active)->toBeFalse();
});

it('deletes a guideline document', function () {
    $admin = flowspecUser(UserRole::Admin);
    $guideline = FlowspecGuideline::factory()->create();

    $this->actingAs($admin)
        ->deleteJson(route('flowspec.guidelines.destroy', $guideline))
        ->assertOk()
        ->assertJson(['type' => 'success']);

    $this->assertModelMissing($guideline);
});

it('renders the guideline management modal for an admin', function () {
    FlowspecGuideline::factory()->count(2)->create();
    $admin = flowspecUser(UserRole::Admin);

    $response = $this->actingAs($admin)->getJson(route('flowspec.guidelines.index'))->assertOk();

    expect($response->json('content'))
        ->toContain('Gerenciar diretrizes')
        ->toContain('Nova diretriz')
        ->toContain('flowspec-guideline-list-slot');
});

it('forbids non-admins from managing guidelines', function () {
    $viewer = flowspecUser();
    $guideline = FlowspecGuideline::factory()->create();
    $payload = ['title' => 'X', 'content' => 'Y'];

    $this->actingAs($viewer)->getJson(route('flowspec.guidelines.index'))->assertForbidden();
    $this->actingAs($viewer)->postJson(route('flowspec.guidelines.store'), $payload)->assertForbidden();
    $this->actingAs($viewer)->patchJson(route('flowspec.guidelines.update', $guideline), $payload)->assertForbidden();
    $this->actingAs($viewer)->deleteJson(route('flowspec.guidelines.destroy', $guideline))->assertForbidden();
});
