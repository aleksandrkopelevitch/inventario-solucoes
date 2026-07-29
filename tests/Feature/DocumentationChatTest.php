<?php

use App\Enums\UserRole;
use App\Jobs\GenerateDocumentationChatReply;
use App\Models\DocumentationChat;
use App\Models\DocumentationPage;
use App\Models\Solution;
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

function chatPage(Solution $solution): DocumentationPage
{
    return DocumentationPage::factory()->for($solution, 'container')->create();
}

it('renders the chat panel with the context document list', function () {
    $solution = Solution::factory()->create();
    $page = chatPage($solution);

    $response = $this->actingAs(chatAdmin())
        ->getJson(route('solutions.docs.chat.panel', [$solution, $page]))
        ->assertOk();

    expect($response->json('content'))
        ->toContain('Especialista em Documentação')
        ->toContain('context-documents-slot')
        ->toContain('data-ak-docs-chat-send')
        // The context document uploads automatically on selection (no separate
        // "Anexar" click), pointed at the store endpoint.
        ->toContain('data-ak-context-upload')
        ->toContain(route('solutions.docs.context.store', $solution));
});

it('opening the panel resumes the same conversation instead of starting a new one', function () {
    $solution = Solution::factory()->create();
    $page = chatPage($solution);
    $admin = chatAdmin();

    $this->actingAs($admin)->getJson(route('solutions.docs.chat.panel', [$solution, $page]))->assertOk();
    $this->actingAs($admin)->getJson(route('solutions.docs.chat.panel', [$solution, $page]))->assertOk();

    expect(DocumentationChat::where('user_id', $admin->id)->count())->toBe(1);
});

it('sends a message and dispatches the reply job', function () {
    Queue::fake();
    $solution = Solution::factory()->create();
    $page = chatPage($solution);

    $response = $this->actingAs(chatAdmin())
        ->postJson(route('solutions.docs.chat.messages.store', [$solution, $page]), [
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
    $solution = Solution::factory()->create();
    $page = chatPage($solution);
    $admin = chatAdmin();
    $chat = DocumentationChat::create([
        'user_id'     => $admin->id,
        'target_type' => $page->getMorphClass(),
        'target_id'   => $page->getKey(),
        'solution_id' => $solution->id,
    ]);
    $chat->messages()->create(['role' => 'user', 'content' => 'oi']);

    $this->actingAs($admin)
        ->getJson(route('solutions.docs.chat.status', [$solution, $chat]))
        ->assertOk()->assertJson(['pending' => true, 'updatableSlots' => []]);

    $chat->messages()->create(['role' => 'assistant', 'content' => 'Pronto.']);

    $response = $this->actingAs($admin)
        ->getJson(route('solutions.docs.chat.status', [$solution, $chat]))
        ->assertOk()->assertJson(['pending' => false]);

    expect($response->json('updatableSlots.0.content'))->toContain('Pronto.');
});

it('rejects a second message while one is still awaiting a reply', function () {
    Queue::fake();
    $solution = Solution::factory()->create();
    $page = chatPage($solution);
    $admin = chatAdmin();

    $this->actingAs($admin)
        ->postJson(route('solutions.docs.chat.messages.store', [$solution, $page]), ['message' => 'primeira'])
        ->assertOk();

    $response = $this->actingAs($admin)
        ->postJson(route('solutions.docs.chat.messages.store', [$solution, $page]), ['message' => 'segunda'])
        ->assertStatus(422)->assertJson(['type' => 'warning']);

    expect($response->json('message'))->toContain('Aguarde a resposta');
    Queue::assertPushed(GenerateDocumentationChatReply::class, 1);
});

it('marks a message applied and 404s a cross-solution mismatch', function () {
    $solution = Solution::factory()->create();
    $other = Solution::factory()->create();
    $page = chatPage($solution);
    $admin = chatAdmin();

    $makeFor = function (Solution $s, DocumentationPage $p) use ($admin) {
        $chat = DocumentationChat::create([
            'user_id'     => $admin->id,
            'target_type' => $p->getMorphClass(),
            'target_id'   => $p->getKey(),
            'solution_id' => $s->id,
        ]);

        return $chat->messages()->create(['role' => 'assistant', 'content' => 'x', 'draft' => '# rascunho']);
    };

    $message = $makeFor($solution, $page);
    $this->actingAs($admin)
        ->postJson(route('solutions.docs.chat.messages.apply', [$solution, $message]))
        ->assertOk()->assertJson(['ok' => true]);
    expect($message->fresh()->applied_at)->not->toBeNull();

    // A message belonging to a genuinely different target/solution — querying
    // it under $solution's URL must 404, not leak across solutions.
    $mismatch = $makeFor($other, chatPage($other));
    $this->actingAs($admin)
        ->postJson(route('solutions.docs.chat.messages.apply', [$solution, $mismatch]))
        ->assertNotFound();

    $viewer = User::factory()->create(['role' => UserRole::Viewer->value]);
    $this->actingAs($viewer)
        ->postJson(route('solutions.docs.chat.messages.apply', [$solution, $message]))
        ->assertForbidden();
});

it('renders a resume marker on the editor while a reply is still generating', function () {
    $solution = Solution::factory()->create();
    $page = chatPage($solution);
    $admin = chatAdmin();

    // No chat yet → no marker.
    $this->actingAs($admin)
        ->get(route('solutions.docs.page.edit', [$solution, $page]))
        ->assertOk()
        ->assertDontSee('data-ak-docs-chat-resume', false);

    $chat = DocumentationChat::create([
        'user_id'     => $admin->id,
        'target_type' => $page->getMorphClass(),
        'target_id'   => $page->getKey(),
        'solution_id' => $solution->id,
    ]);
    $chat->messages()->create(['role' => 'user', 'content' => 'oi']);

    // Awaiting a reply → marker present.
    $this->actingAs($admin)
        ->get(route('solutions.docs.page.edit', [$solution, $page]))
        ->assertOk()
        ->assertSee('data-ak-docs-chat-resume', false);

    // Once the reply lands, the chat is no longer awaiting → no marker.
    $chat->messages()->create(['role' => 'assistant', 'content' => 'pronto']);
    $this->actingAs($admin)
        ->get(route('solutions.docs.page.edit', [$solution, $page]))
        ->assertOk()
        ->assertDontSee('data-ak-docs-chat-resume', false);
});

it('does not resume another user\'s chat', function () {
    $solution = Solution::factory()->create();
    $page = chatPage($solution);

    $chat = DocumentationChat::create([
        'user_id'     => chatAdmin()->id, // a different admin
        'target_type' => $page->getMorphClass(),
        'target_id'   => $page->getKey(),
        'solution_id' => $solution->id,
    ]);
    $chat->messages()->create(['role' => 'user', 'content' => 'oi']);

    $this->actingAs(chatAdmin())
        ->get(route('solutions.docs.page.edit', [$solution, $page]))
        ->assertOk()
        ->assertDontSee('data-ak-docs-chat-resume', false);
});

it('404s a status request for a chat belonging to another solution', function () {
    $solution = Solution::factory()->create();
    $other = Solution::factory()->create();
    $page = chatPage($solution);
    $chat = DocumentationChat::create([
        'user_id'     => chatAdmin()->id,
        'target_type' => $page->getMorphClass(),
        'target_id'   => $page->getKey(),
        'solution_id' => $other->id, // belongs to another solution
    ]);

    $this->actingAs(chatAdmin())
        ->getJson(route('solutions.docs.chat.status', [$solution, $chat]))
        ->assertNotFound();
});

it('rejects an empty message with a warning', function () {
    $solution = Solution::factory()->create();
    $page = chatPage($solution);

    $response = $this->actingAs(chatAdmin())
        ->postJson(route('solutions.docs.chat.messages.store', [$solution, $page]), ['message' => ''])
        ->assertStatus(422)->assertJson(['type' => 'warning']);

    expect($response->json('message'))->not->toBeEmpty();
});

it('forbids a non-admin from sending a message', function () {
    $solution = Solution::factory()->create();
    $page = chatPage($solution);
    $viewer = User::factory()->create(['role' => UserRole::Viewer->value]);

    $this->actingAs($viewer)
        ->postJson(route('solutions.docs.chat.messages.store', [$solution, $page]), ['message' => 'oi'])
        ->assertForbidden();
});

it('stores a context document on the solution and returns the list slot', function () {
    Storage::fake('public');
    $solution = Solution::factory()->create();

    $response = $this->actingAs(chatAdmin())
        ->post(route('solutions.docs.context.store', $solution), [
            'file' => UploadedFile::fake()->create('contrato.pdf', 100, 'application/pdf'),
        ])->assertOk();

    expect($solution->fresh()->getMedia(Solution::CONTEXT_COLLECTION))->toHaveCount(1)
        ->and($response->json('updatableSlots.0.id'))->toBe('context-documents-slot');
});

it('removes a context document from the solution', function () {
    Storage::fake('public');
    $solution = Solution::factory()->create();
    $media = $solution->addMediaFromString('x')->usingFileName('a.txt')->toMediaCollection(Solution::CONTEXT_COLLECTION);

    $this->actingAs(chatAdmin())
        ->deleteJson(route('solutions.docs.context.destroy', [$solution, $media->id]))
        ->assertOk();

    expect($solution->fresh()->getMedia(Solution::CONTEXT_COLLECTION))->toHaveCount(0);
});

it('forbids a non-admin from storing or removing a context document', function () {
    Storage::fake('public');
    $solution = Solution::factory()->create();
    $media = $solution->addMediaFromString('x')->usingFileName('a.txt')->toMediaCollection(Solution::CONTEXT_COLLECTION);
    $viewer = User::factory()->create(['role' => UserRole::Viewer->value]);

    $this->actingAs($viewer)
        ->post(route('solutions.docs.context.store', $solution), [
            'file' => UploadedFile::fake()->create('contrato.pdf', 100, 'application/pdf'),
        ])->assertForbidden();

    $this->actingAs($viewer)
        ->deleteJson(route('solutions.docs.context.destroy', [$solution, $media->id]))
        ->assertForbidden();

    expect($solution->fresh()->getMedia(Solution::CONTEXT_COLLECTION))->toHaveCount(1);
});

it('reopens the composer after the stall window, treating a dead job as resolved', function () {
    // A worker killed before it fires handle()/failed() never creates the
    // assistant reply, so the last message stays role='user'. Past
    // REPLY_STALL_SECONDS the generation is treated as dead — the composer
    // must recover instead of locking the chat out forever.
    Queue::fake();
    $solution = Solution::factory()->create();
    $page = chatPage($solution);
    $admin = chatAdmin();
    $chat = DocumentationChat::create([
        'user_id'     => $admin->id,
        'target_type' => $page->getMorphClass(),
        'target_id'   => $page->getKey(),
        'solution_id' => $solution->id,
    ]);
    $chat->messages()->create(['role' => 'user', 'content' => 'gera aí']);

    $this->travel(DocumentationChat::REPLY_STALL_SECONDS + 60)->seconds();

    $this->actingAs($admin)
        ->postJson(route('solutions.docs.chat.messages.store', [$solution, $page]), ['message' => 'mais uma'])
        ->assertOk();

    expect($chat->messages()->count())->toBe(2);
    Queue::assertPushed(GenerateDocumentationChatReply::class);
});

it('writes no reply for a turn a later message has superseded', function () {
    // Covers both a job the queue resurrects after a hard worker kill (once
    // the stall guard reopened the composer and the user sent again) and a
    // double-submit race that slipped two messages past the controller guard.
    $solution = Solution::factory()->create();
    $page = chatPage($solution);
    $chat = DocumentationChat::create([
        'user_id'     => chatAdmin()->id,
        'target_type' => $page->getMorphClass(),
        'target_id'   => $page->getKey(),
        'solution_id' => $solution->id,
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
    $solution = Solution::factory()->create();
    $page = chatPage($solution);
    $chat = DocumentationChat::create([
        'user_id'     => chatAdmin()->id,
        'target_type' => $page->getMorphClass(),
        'target_id'   => $page->getKey(),
        'solution_id' => $solution->id,
    ]);
    $stale = $chat->messages()->create(['role' => 'user', 'content' => 'primeira']);
    $chat->messages()->create(['role' => 'assistant', 'content' => 'resposta do turno atual']);

    (new GenerateDocumentationChatReply($stale))->failed(new RuntimeException('api down'));

    // The current turn already has its reply — the stale job's failed() adds nothing.
    expect($chat->messages()->where('role', 'assistant')->count())->toBe(1);
});

it('persists only the exception type on failure, never the raw provider message', function () {
    $solution = Solution::factory()->create();
    $page = chatPage($solution);
    $chat = DocumentationChat::create([
        'user_id'     => chatAdmin()->id,
        'target_type' => $page->getMorphClass(),
        'target_id'   => $page->getKey(),
        'solution_id' => $solution->id,
    ]);
    $stale = $chat->messages()->create(['role' => 'user', 'content' => 'gera aí']);

    (new GenerateDocumentationChatReply($stale))->failed(new RuntimeException('POST https://api.example.com key=sk-secret-123'));

    $reply = $chat->messages()->where('role', 'assistant')->firstOrFail();

    expect($reply->meta['error_type'])->toBe(RuntimeException::class)
        ->and($reply->meta)->not->toHaveKey('error')
        ->and(json_encode($reply->meta))->not->toContain('sk-secret-123');
});

it('declares WithoutOverlapping middleware keyed by the chat, so concurrent messages serialize', function () {
    $solution = Solution::factory()->create();
    $page = chatPage($solution);
    $chat = DocumentationChat::create([
        'user_id'     => chatAdmin()->id,
        'target_type' => $page->getMorphClass(),
        'target_id'   => $page->getKey(),
        'solution_id' => $solution->id,
    ]);
    $message = $chat->messages()->create(['role' => 'user', 'content' => 'gera aí']);

    $job = new GenerateDocumentationChatReply($message);
    $middleware = $job->middleware();

    expect($middleware)->toHaveCount(1)
        ->and($middleware[0])->toBeInstanceOf(WithoutOverlapping::class)
        ->and((int) $middleware[0]->key)->toBe($chat->id);
});
