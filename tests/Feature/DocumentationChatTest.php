<?php

use App\Enums\UserRole;
use App\Jobs\GenerateDocumentationChatReply;
use App\Models\DocumentationChat;
use App\Models\DocumentationPage;
use App\Models\Notebook;
use App\Models\User;
use App\Services\Documentation\DocumentationChatService;
use Illuminate\Foundation\Testing\LazilyRefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Queue\Middleware\WithoutOverlapping;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Storage;

uses(LazilyRefreshDatabase::class);

function chatAdmin(): User
{
    return User::factory()->create(['role' => UserRole::Admin->value]);
}

function chatPage(Notebook $notebook): DocumentationPage
{
    return DocumentationPage::factory()->for($notebook)->create();
}

it('renders the chat panel with the context document list', function () {
    $notebook = Notebook::factory()->create();
    $page = chatPage($notebook);

    $response = $this->actingAs(chatAdmin())
        ->getJson(route('notebooks.chat.panel', [$notebook, $page]))
        ->assertOk();

    expect($response->json('content'))
        ->toContain('Especialista em Documentação')
        ->toContain('context-documents-slot')
        ->toContain('data-ak-docs-chat-send')
        // The context document uploads automatically on selection (no separate
        // "Anexar" click), pointed at the store endpoint.
        ->toContain('data-ak-context-upload')
        ->toContain(route('notebooks.context.store', $notebook));
});

it('opening the panel resumes the same conversation instead of starting a new one', function () {
    $notebook = Notebook::factory()->create();
    $page = chatPage($notebook);
    $admin = chatAdmin();

    $this->actingAs($admin)->getJson(route('notebooks.chat.panel', [$notebook, $page]))->assertOk();
    $this->actingAs($admin)->getJson(route('notebooks.chat.panel', [$notebook, $page]))->assertOk();

    expect(DocumentationChat::where('user_id', $admin->id)->count())->toBe(1);
});

it('sends a message and dispatches the reply job', function () {
    Queue::fake();
    $notebook = Notebook::factory()->create();
    $page = chatPage($notebook);

    $response = $this->actingAs(chatAdmin())
        ->postJson(route('notebooks.chat.messages.store', [$notebook, $page]), [
            'message'          => 'documente o fluxo',
            'media_ids'        => [],
            'existing_content' => '# atual',
        ])->assertOk();

    expect($response->json('updatableSlots.0.id'))->toBe('documentation-chat-thread-slot')
        ->and($response->json('updatableSlots.0.content'))->toContain('documente o fluxo');

    Queue::assertPushed(GenerateDocumentationChatReply::class);
    $this->assertDatabaseHas('documentation_chat_messages', [
        'role'    => 'user',
        'content' => 'documente o fluxo',
    ]);
});

it('reports pending while awaiting a reply, then the rendered thread once it arrives', function () {
    $notebook = Notebook::factory()->create();
    $page = chatPage($notebook);
    $admin = chatAdmin();
    $chat = DocumentationChat::create([
        'user_id'     => $admin->id,
        'target_type' => $page->getMorphClass(),
        'target_id'   => $page->getKey(),
        'notebook_id' => $notebook->id,
    ]);
    $chat->messages()->create(['role' => 'user', 'content' => 'oi']);

    $this->actingAs($admin)
        ->getJson(route('notebooks.chat.status', [$notebook, $chat]))
        ->assertOk()->assertJson(['pending' => true, 'updatableSlots' => []]);

    $chat->messages()->create(['role' => 'assistant', 'content' => 'Pronto.']);

    $response = $this->actingAs($admin)
        ->getJson(route('notebooks.chat.status', [$notebook, $chat]))
        ->assertOk()->assertJson(['pending' => false]);

    expect($response->json('updatableSlots.0.content'))->toContain('Pronto.');
});

it('rejects a second message while one is still awaiting a reply', function () {
    Queue::fake();
    $notebook = Notebook::factory()->create();
    $page = chatPage($notebook);
    $admin = chatAdmin();

    $this->actingAs($admin)
        ->postJson(route('notebooks.chat.messages.store', [$notebook, $page]), ['message' => 'primeira'])
        ->assertOk();

    $response = $this->actingAs($admin)
        ->postJson(route('notebooks.chat.messages.store', [$notebook, $page]), ['message' => 'segunda'])
        ->assertStatus(422)->assertJson(['type' => 'warning']);

    expect($response->json('message'))->toContain('Aguarde a resposta');
    Queue::assertPushed(GenerateDocumentationChatReply::class, 1);
});

it('marks a message applied and 404s a cross-solution mismatch', function () {
    $notebook = Notebook::factory()->create();
    $other = Notebook::factory()->create();
    $page = chatPage($notebook);
    $admin = chatAdmin();

    $makeFor = function (Notebook $s, DocumentationPage $p) use ($admin) {
        $chat = DocumentationChat::create([
            'user_id'     => $admin->id,
            'target_type' => $p->getMorphClass(),
            'target_id'   => $p->getKey(),
            'notebook_id' => $s->id,
        ]);

        return $chat->messages()->create(['role' => 'assistant', 'content' => 'x', 'draft' => '# rascunho']);
    };

    $message = $makeFor($notebook, $page);
    $this->actingAs($admin)
        ->postJson(route('notebooks.chat.messages.apply', [$notebook, $message]))
        ->assertOk()->assertJson(['ok' => true]);
    expect($message->fresh()->applied_at)->not->toBeNull();

    // A message belonging to a genuinely different target/caderno — querying
    // it under $notebook's URL must 404, not leak across cadernos.
    $mismatch = $makeFor($other, chatPage($other));
    $this->actingAs($admin)
        ->postJson(route('notebooks.chat.messages.apply', [$notebook, $mismatch]))
        ->assertNotFound();

    $viewer = User::factory()->create(['role' => UserRole::Viewer->value]);
    $this->actingAs($viewer)
        ->postJson(route('notebooks.chat.messages.apply', [$notebook, $message]))
        ->assertForbidden();
});

it('renders a resume marker on the editor while a reply is still generating', function () {
    $notebook = Notebook::factory()->create();
    $page = chatPage($notebook);
    $admin = chatAdmin();

    // No chat yet → no marker.
    $this->actingAs($admin)
        ->get(route('notebooks.pages.edit', [$notebook, $page]))
        ->assertOk()
        ->assertDontSee('data-ak-docs-chat-resume', false);

    $chat = DocumentationChat::create([
        'user_id'     => $admin->id,
        'target_type' => $page->getMorphClass(),
        'target_id'   => $page->getKey(),
        'notebook_id' => $notebook->id,
    ]);
    $chat->messages()->create(['role' => 'user', 'content' => 'oi']);

    // Awaiting a reply → marker present.
    $this->actingAs($admin)
        ->get(route('notebooks.pages.edit', [$notebook, $page]))
        ->assertOk()
        ->assertSee('data-ak-docs-chat-resume', false);

    // Once the reply lands, the chat is no longer awaiting → no marker.
    $chat->messages()->create(['role' => 'assistant', 'content' => 'pronto']);
    $this->actingAs($admin)
        ->get(route('notebooks.pages.edit', [$notebook, $page]))
        ->assertOk()
        ->assertDontSee('data-ak-docs-chat-resume', false);
});

it('does not resume another user\'s chat', function () {
    $notebook = Notebook::factory()->create();
    $page = chatPage($notebook);

    $chat = DocumentationChat::create([
        'user_id'     => chatAdmin()->id, // a different admin
        'target_type' => $page->getMorphClass(),
        'target_id'   => $page->getKey(),
        'notebook_id' => $notebook->id,
    ]);
    $chat->messages()->create(['role' => 'user', 'content' => 'oi']);

    $this->actingAs(chatAdmin())
        ->get(route('notebooks.pages.edit', [$notebook, $page]))
        ->assertOk()
        ->assertDontSee('data-ak-docs-chat-resume', false);
});

it('404s a status request for a chat belonging to another solution', function () {
    $notebook = Notebook::factory()->create();
    $other = Notebook::factory()->create();
    $page = chatPage($notebook);
    $chat = DocumentationChat::create([
        'user_id'     => chatAdmin()->id,
        'target_type' => $page->getMorphClass(),
        'target_id'   => $page->getKey(),
        'notebook_id' => $other->id, // belongs to another caderno
    ]);

    $this->actingAs(chatAdmin())
        ->getJson(route('notebooks.chat.status', [$notebook, $chat]))
        ->assertNotFound();
});

it('rejects an empty message with a warning', function () {
    $notebook = Notebook::factory()->create();
    $page = chatPage($notebook);

    $response = $this->actingAs(chatAdmin())
        ->postJson(route('notebooks.chat.messages.store', [$notebook, $page]), ['message' => ''])
        ->assertStatus(422)->assertJson(['type' => 'warning']);

    expect($response->json('message'))->not->toBeEmpty();
});

it('forbids a non-admin from sending a message', function () {
    $notebook = Notebook::factory()->create();
    $page = chatPage($notebook);
    $viewer = User::factory()->create(['role' => UserRole::Viewer->value]);

    $this->actingAs($viewer)
        ->postJson(route('notebooks.chat.messages.store', [$notebook, $page]), ['message' => 'oi'])
        ->assertForbidden();
});

it('stores a context document on the solution and returns the list slot', function () {
    Storage::fake('public');
    $notebook = Notebook::factory()->create();

    $response = $this->actingAs(chatAdmin())
        ->post(route('notebooks.context.store', $notebook), [
            'file' => UploadedFile::fake()->create('contrato.pdf', 100, 'application/pdf'),
        ])->assertOk();

    expect($notebook->fresh()->getMedia(Notebook::CONTEXT_COLLECTION))->toHaveCount(1)
        ->and($response->json('updatableSlots.0.id'))->toBe('context-documents-slot');
});

it('removes a context document from the solution', function () {
    Storage::fake('public');
    $notebook = Notebook::factory()->create();
    $media = $notebook->addMediaFromString('x')->usingFileName('a.txt')->toMediaCollection(Notebook::CONTEXT_COLLECTION);

    $this->actingAs(chatAdmin())
        ->deleteJson(route('notebooks.context.destroy', [$notebook, $media->id]))
        ->assertOk();

    expect($notebook->fresh()->getMedia(Notebook::CONTEXT_COLLECTION))->toHaveCount(0);
});

it('forbids a non-admin from storing or removing a context document', function () {
    Storage::fake('public');
    $notebook = Notebook::factory()->create();
    $media = $notebook->addMediaFromString('x')->usingFileName('a.txt')->toMediaCollection(Notebook::CONTEXT_COLLECTION);
    $viewer = User::factory()->create(['role' => UserRole::Viewer->value]);

    $this->actingAs($viewer)
        ->post(route('notebooks.context.store', $notebook), [
            'file' => UploadedFile::fake()->create('contrato.pdf', 100, 'application/pdf'),
        ])->assertForbidden();

    $this->actingAs($viewer)
        ->deleteJson(route('notebooks.context.destroy', [$notebook, $media->id]))
        ->assertForbidden();

    expect($notebook->fresh()->getMedia(Notebook::CONTEXT_COLLECTION))->toHaveCount(1);
});

it('reopens the composer after the stall window, treating a dead job as resolved', function () {
    // A worker killed before it fires handle()/failed() never creates the
    // assistant reply, so the last message stays role='user'. Past
    // REPLY_STALL_SECONDS the generation is treated as dead — the composer
    // must recover instead of locking the chat out forever.
    Queue::fake();
    $notebook = Notebook::factory()->create();
    $page = chatPage($notebook);
    $admin = chatAdmin();
    $chat = DocumentationChat::create([
        'user_id'     => $admin->id,
        'target_type' => $page->getMorphClass(),
        'target_id'   => $page->getKey(),
        'notebook_id' => $notebook->id,
    ]);
    $chat->messages()->create(['role' => 'user', 'content' => 'gera aí']);

    $this->travel(DocumentationChat::REPLY_STALL_SECONDS + 60)->seconds();

    $this->actingAs($admin)
        ->postJson(route('notebooks.chat.messages.store', [$notebook, $page]), ['message' => 'mais uma'])
        ->assertOk();

    expect($chat->messages()->count())->toBe(2);
    Queue::assertPushed(GenerateDocumentationChatReply::class);
});

it('writes no reply for a turn a later message has superseded', function () {
    // Covers both a job the queue resurrects after a hard worker kill (once
    // the stall guard reopened the composer and the user sent again) and a
    // double-submit race that slipped two messages past the controller guard.
    $notebook = Notebook::factory()->create();
    $page = chatPage($notebook);
    $chat = DocumentationChat::create([
        'user_id'     => chatAdmin()->id,
        'target_type' => $page->getMorphClass(),
        'target_id'   => $page->getKey(),
        'notebook_id' => $notebook->id,
    ]);
    $stale = $chat->messages()->create(['role' => 'user', 'content' => 'primeira']);
    $chat->messages()->create(['role' => 'user', 'content' => 'segunda — supersede']);

    // isSuperseded() must short-circuit before generate() is ever called.
    $service = Mockery::mock(DocumentationChatService::class);
    $service->shouldNotReceive('generate');

    (new GenerateDocumentationChatReply($stale))->handle($service);

    expect($chat->messages()->where('role', 'assistant')->count())->toBe(0)
        ->and($chat->messages()->count())->toBe(2);
});

it('writes no failure reply for a superseded turn', function () {
    $notebook = Notebook::factory()->create();
    $page = chatPage($notebook);
    $chat = DocumentationChat::create([
        'user_id'     => chatAdmin()->id,
        'target_type' => $page->getMorphClass(),
        'target_id'   => $page->getKey(),
        'notebook_id' => $notebook->id,
    ]);
    $stale = $chat->messages()->create(['role' => 'user', 'content' => 'primeira']);
    $chat->messages()->create(['role' => 'assistant', 'content' => 'resposta do turno atual']);

    (new GenerateDocumentationChatReply($stale))->failed(new RuntimeException('api down'));

    // The current turn already has its reply — the stale job's failed() adds nothing.
    expect($chat->messages()->where('role', 'assistant')->count())->toBe(1);
});

it('persists only the exception type on failure, never the raw provider message', function () {
    $notebook = Notebook::factory()->create();
    $page = chatPage($notebook);
    $chat = DocumentationChat::create([
        'user_id'     => chatAdmin()->id,
        'target_type' => $page->getMorphClass(),
        'target_id'   => $page->getKey(),
        'notebook_id' => $notebook->id,
    ]);
    $stale = $chat->messages()->create(['role' => 'user', 'content' => 'gera aí']);

    (new GenerateDocumentationChatReply($stale))->failed(new RuntimeException('POST https://api.example.com key=sk-secret-123'));

    $reply = $chat->messages()->where('role', 'assistant')->firstOrFail();

    expect($reply->meta['error_type'])->toBe(RuntimeException::class)
        ->and($reply->meta)->not->toHaveKey('error')
        ->and(json_encode($reply->meta))->not->toContain('sk-secret-123');
});

it('declares WithoutOverlapping middleware keyed by the chat, so concurrent messages serialize', function () {
    $notebook = Notebook::factory()->create();
    $page = chatPage($notebook);
    $chat = DocumentationChat::create([
        'user_id'     => chatAdmin()->id,
        'target_type' => $page->getMorphClass(),
        'target_id'   => $page->getKey(),
        'notebook_id' => $notebook->id,
    ]);
    $message = $chat->messages()->create(['role' => 'user', 'content' => 'gera aí']);

    $job = new GenerateDocumentationChatReply($message);
    $middleware = $job->middleware();

    expect($middleware)->toHaveCount(1)
        ->and($middleware[0])->toBeInstanceOf(WithoutOverlapping::class)
        ->and((int) $middleware[0]->key)->toBe($chat->id);
});

/*
|--------------------------------------------------------------------------
| A long paste becomes a context document
|--------------------------------------------------------------------------
|
| The same gesture the Especialista em Integrações composer has, arriving here
| because the material is the same. Where it LANDS differs on purpose: F8's
| context is chat-scoped, a caderno's context documents belong to the notebook
| and are shared by every page in it — so this paste is still there while
| documenting the next page.
|
*/

it('turns a long paste into a context document of the caderno', function () {
    Storage::fake('public');
    $notebook = Notebook::factory()->create();

    $response = $this->actingAs(chatAdmin())
        ->postJson(route('notebooks.context.store', $notebook), [
            'text' => "Contrato de integração ERP\n" . str_repeat('detalhe do contrato. ', 200),
        ])->assertOk();

    $media = $notebook->fresh()->getMedia(Notebook::CONTEXT_COLLECTION);

    expect($media)->toHaveCount(1)
        // Named after its own first line, which is what a person recognizes it
        // by in a column of checkboxes.
        ->and($media->first()->file_name)->toBe('contrato-de-integracao-erp.txt')
        ->and($response->json('updatableSlots.0.id'))->toBe('context-documents-slot');
});

it('minifies a pasted pipeline and stores it as json', function () {
    Storage::fake('public');
    $notebook = Notebook::factory()->create();

    $raw = json_encode([
        'meta'     => ['abc' => ['position' => ['x' => 200, 'y' => 0]]],
        'flowSpec' => ['root' => [['id' => 'abc', 'name' => 'log-connector']]],
    ], JSON_PRETTY_PRINT);

    $this->actingAs(chatAdmin())
        ->postJson(route('notebooks.context.store', $notebook), ['text' => $raw])
        ->assertOk();

    $media = $notebook->fresh()->getMedia(Notebook::CONTEXT_COLLECTION)->first();
    $stored = file_get_contents($media->getPath());

    // The canvas position map is dropped and the whitespace with it. This is
    // not cosmetic: ContextDocumentResolver TRUNCATES a text document past
    // doc_budget_chars, and a JSON cut mid-object is unreadable to the model.
    expect($media->file_name)->toBe('flowspec-colado.json')
        ->and($stored)->not->toContain('position')
        ->and($stored)->not->toContain("\n")
        ->and(json_decode($stored, true))->toBe([
            'flowSpec' => ['root' => [['id' => 'abc', 'name' => 'log-connector']]],
        ]);
});

it('numbers a repeated paste name so the checkbox list has no two identical rows', function () {
    Storage::fake('public');
    $notebook = Notebook::factory()->create();
    $raw = '{"flowSpec":{"a":[]}}';

    $this->actingAs(chatAdmin())->postJson(route('notebooks.context.store', $notebook), ['text' => $raw])->assertOk();
    $this->actingAs(chatAdmin())->postJson(route('notebooks.context.store', $notebook), ['text' => $raw])->assertOk();

    expect($notebook->fresh()->getMedia(Notebook::CONTEXT_COLLECTION)->pluck('file_name')->all())
        ->toBe(['flowspec-colado.json', 'flowspec-colado-2.json']);
});

it('still accepts a picked file, and refuses a request carrying neither', function () {
    Storage::fake('public');
    $notebook = Notebook::factory()->create();

    $this->actingAs(chatAdmin())
        ->post(route('notebooks.context.store', $notebook), [
            'file' => UploadedFile::fake()->create('contrato.pdf', 100, 'application/pdf'),
        ])->assertOk();

    $this->actingAs(chatAdmin())
        ->postJson(route('notebooks.context.store', $notebook), [])
        ->assertStatus(422);

    expect($notebook->fresh()->getMedia(Notebook::CONTEXT_COLLECTION))->toHaveCount(1);
});

it('forbids a viewer from pasting context, like any other attach', function () {
    Storage::fake('public');
    $notebook = Notebook::factory()->create();
    $viewer = User::factory()->create(['role' => UserRole::Viewer->value]);

    $this->actingAs($viewer)
        ->postJson(route('notebooks.context.store', $notebook), ['text' => str_repeat('a', 3000)])
        ->assertForbidden();

    expect($notebook->fresh()->getMedia(Notebook::CONTEXT_COLLECTION))->toHaveCount(0);
});

// The threshold is served to the client from config, so the composer and
// StoreContextDocumentRequest cannot drift on where "long" starts.
it('serves the paste threshold to the composer', function () {
    $notebook = Notebook::factory()->create();
    $page = DocumentationPage::factory()->for($notebook)->create();

    $this->actingAs(chatAdmin())
        ->getJson(route('notebooks.chat.panel', [$notebook, $page]))
        ->assertOk()
        ->assertSee('data-ak-docs-chat-composer', escape: false)
        ->assertSee('pasteThreshold', escape: false);
});
