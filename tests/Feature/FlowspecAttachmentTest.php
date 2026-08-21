<?php

use App\Enums\ContextExtractionState;
use App\Enums\FlowspecAttachmentKind;
use App\Enums\UserRole;
use App\Jobs\GenerateFlowspecReply;
use App\Models\DocumentationPage;
use App\Models\FlowspecAttachment;
use App\Models\FlowspecChat;
use App\Models\Integration;
use App\Models\Solution;
use App\Models\User;
use App\Services\Flowspec\FlowspecContextResolver;
use App\View\Components\Flowspec\ContextPanel;
use Illuminate\Foundation\Testing\LazilyRefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Storage;

uses(LazilyRefreshDatabase::class);

function attachmentUser(UserRole $role = UserRole::Viewer): User
{
    return User::factory()->create(['role' => $role->value]);
}

function documentedPage(string $title = 'Contrato', string $body = 'POST /colaboradores'): DocumentationPage
{
    return DocumentationPage::factory()
        ->for(Solution::factory()->create(['name' => 'SVL']), 'container')
        ->create(['title' => $title, 'documentation' => $body]);
}

/*
|--------------------------------------------------------------------------
| Context belongs to the conversation
|--------------------------------------------------------------------------
|
| This is the behavior change the whole feature rests on. The old per-message
| pickers were reset after every send, so the second question in a thread was
| answered without the documentation the first one had. These tests are the
| guard on that not coming back.
|
*/

it('attaches inventory documentation to the conversation, not to one message', function () {
    Queue::fake();
    $user = attachmentUser();
    $chat = FlowspecChat::factory()->for($user)->create();
    $page = documentedPage();

    $this->actingAs($user)
        ->postJson(route('flowspec.attachments.store', $chat), ['documents' => ["page:{$page->id}"]])
        ->assertOk()
        ->assertJsonPath('updatableSlots.0.id', ContextPanel::DOM_ID);

    $this->actingAs($user)
        ->postJson(route('flowspec.messages.store', $chat), ['message' => 'primeira pergunta'])
        ->assertOk();

    // The whole point: still there after a turn was sent.
    expect($chat->attachments()->count())->toBe(1)
        ->and($chat->attachments()->first()->kind)->toBe(FlowspecAttachmentKind::Document);
});

it('carries context staged in the new-chat composer into the conversation it creates', function () {
    Queue::fake();
    Storage::fake('local');
    $user = attachmentUser();
    $page = documentedPage();

    $this->actingAs($user)->post(route('flowspec.store'), [
        'message'   => 'gera o pipeline',
        'documents' => ["page:{$page->id}"],
        'texts'     => [['content' => str_repeat('contrato colado ', 200), 'label' => 'Contrato colado']],
        'files'     => [UploadedFile::fake()->createWithContent('spec.md', '# Endpoints')],
    ], ['Accept' => 'application/json'])->assertOk();

    $chat = FlowspecChat::query()->firstOrFail();

    expect($chat->attachments()->pluck('kind')->map->value->all())
        ->toBe(['document', 'file', 'text'])
        ->and($chat->messages()->count())->toBe(1);

    Queue::assertPushed(GenerateFlowspecReply::class);
});

it('attaches a document before the job is dispatched, so the first turn already sees it', function () {
    $user = attachmentUser();
    $page = documentedPage();
    $seen = null;

    // The job carries only the message; the context is read off the chat when it
    // runs. If store() dispatched before attaching, the very turn the user
    // staged context for would be answered without it.
    Queue::fake();
    Queue::before(function () {});

    $this->actingAs($user)->post(route('flowspec.store'), [
        'message'   => 'gera o pipeline',
        'documents' => ["page:{$page->id}"],
    ], ['Accept' => 'application/json'])->assertOk();

    Queue::assertPushed(GenerateFlowspecReply::class, function (GenerateFlowspecReply $job) use (&$seen) {
        $seen = $job->userMessage->chat->attachments()->count();

        return true;
    });

    expect($seen)->toBe(1);
});

it('is idempotent: re-attaching the same document says so instead of duplicating it', function () {
    $user = attachmentUser();
    $chat = FlowspecChat::factory()->for($user)->create();
    $page = documentedPage();

    $this->actingAs($user)->postJson(route('flowspec.attachments.store', $chat), ['documents' => ["page:{$page->id}"]]);

    $response = $this->actingAs($user)
        ->postJson(route('flowspec.attachments.store', $chat), ['documents' => ["page:{$page->id}"]])
        ->assertOk();

    expect($chat->attachments()->count())->toBe(1)
        ->and($response->json('message'))->toContain('já estava no contexto');
});

it('attaches an integration\'s own documentation', function () {
    $user = attachmentUser();
    $chat = FlowspecChat::factory()->for($user)->create();
    $integration = Integration::factory()->create(['name' => 'IAM -> SVL', 'documentation' => 'fluxo completo']);

    $this->actingAs($user)
        ->postJson(route('flowspec.attachments.store', $chat), ['documents' => ["integration:{$integration->id}"]])
        ->assertOk();

    expect($chat->attachments()->first()->reference_type)->toBe(Integration::class);
});

/*
|--------------------------------------------------------------------------
| Material from the user's disk
|--------------------------------------------------------------------------
*/

it('reads an uploaded text file into the context', function () {
    Storage::fake('local');
    $user = attachmentUser();
    $chat = FlowspecChat::factory()->for($user)->create();

    $this->actingAs($user)->post(route('flowspec.attachments.store', $chat), [
        'file' => UploadedFile::fake()->createWithContent('contrato.md', "# Contrato\nPOST /colaboradores"),
    ], ['Accept' => 'application/json'])->assertOk();

    $attachment = $chat->attachments()->firstOrFail();

    expect($attachment->kind)->toBe(FlowspecAttachmentKind::File)
        ->and($attachment->extraction_state)->toBe(ContextExtractionState::Done)
        ->and($attachment->content)->toContain('POST /colaboradores')
        ->and($attachment->token_estimate)->toBeGreaterThan(0)
        ->and($attachment->media)->not->toBeNull();
});

it('keeps a PDF unextracted so the model reads it natively, and does not call that a failure', function () {
    Storage::fake('local');
    $user = attachmentUser();
    $chat = FlowspecChat::factory()->for($user)->create();

    $this->actingAs($user)->post(route('flowspec.attachments.store', $chat), [
        'file' => UploadedFile::fake()->create('arquitetura.pdf', 120, 'application/pdf'),
    ], ['Accept' => 'application/json'])->assertOk();

    $attachment = $chat->attachments()->firstOrFail();

    expect($attachment->extraction_state)->toBe(ContextExtractionState::Skipped)
        ->and($attachment->content)->toBeNull()
        ->and($attachment->isNativeAttachment())->toBeTrue()
        // It still costs context even with no text — the bytes go to the model.
        ->and($attachment->token_estimate)->toBeGreaterThan(0);

    $context = app(FlowspecContextResolver::class)->resolve($chat, 'gera o pipeline');

    expect($context->attachments)->toHaveCount(1)
        ->and($context->attachedMeta[0]['kind'])->toBe('pdf');
});

it('sends an attached image to the model as an image', function () {
    Storage::fake('local');
    $user = attachmentUser();
    $chat = FlowspecChat::factory()->for($user)->create();

    $this->actingAs($user)->post(route('flowspec.attachments.store', $chat), [
        'file' => UploadedFile::fake()->image('diagrama.png'),
    ], ['Accept' => 'application/json'])->assertOk();

    $context = app(FlowspecContextResolver::class)->resolve($chat, 'gera o pipeline');

    expect($context->attachedMeta[0]['kind'])->toBe('image')
        ->and($context->attachments[0])->toBeInstanceOf(Laravel\Ai\Files\LocalImage::class);
});

it('refuses a format it cannot use as context', function () {
    Storage::fake('local');
    $user = attachmentUser();
    $chat = FlowspecChat::factory()->for($user)->create();

    $response = $this->actingAs($user)->post(route('flowspec.attachments.store', $chat), [
        'file' => UploadedFile::fake()->create('backup.zip', 10, 'application/zip'),
    ], ['Accept' => 'application/json'])->assertStatus(422);

    expect($response->json('message'))->toContain('Formato não aceito')
        ->and($chat->attachments()->count())->toBe(0);
});

it('turns a long paste into a text attachment', function () {
    $user = attachmentUser();
    $chat = FlowspecChat::factory()->for($user)->create();

    $this->actingAs($user)->postJson(route('flowspec.attachments.store', $chat), [
        'text'  => "Contrato da API\n" . str_repeat('POST /colaboradores devolve 201. ', 100),
        'label' => 'Contrato da API',
    ])->assertOk();

    $attachment = $chat->attachments()->firstOrFail();

    expect($attachment->kind)->toBe(FlowspecAttachmentKind::Text)
        ->and($attachment->label)->toBe('Contrato da API')
        ->and($attachment->hasInlineText())->toBeTrue();
});

it('requires at least one of the three kinds of context', function () {
    $user = attachmentUser();
    $chat = FlowspecChat::factory()->for($user)->create();

    $response = $this->actingAs($user)
        ->postJson(route('flowspec.attachments.store', $chat), [])
        ->assertStatus(422);

    expect($response->json('message'))->toContain('Escolha um documento');
});

/*
|--------------------------------------------------------------------------
| Removing
|--------------------------------------------------------------------------
*/

it('removes an attachment and its file from the conversation', function () {
    Storage::fake('local');
    $user = attachmentUser();
    $chat = FlowspecChat::factory()->for($user)->create();

    $this->actingAs($user)->post(route('flowspec.attachments.store', $chat), [
        'file' => UploadedFile::fake()->createWithContent('contrato.md', '# Contrato'),
    ], ['Accept' => 'application/json']);

    $attachment = $chat->attachments()->firstOrFail();
    $mediaId = $attachment->media_id;

    $this->actingAs($user)
        ->deleteJson(route('flowspec.attachments.destroy', [$chat, $attachment]))
        ->assertOk()
        ->assertJsonPath('updatableSlots.0.id', ContextPanel::DOM_ID);

    // The file goes with it: a chat attachment has no audit value once taken
    // back out, and leaving it on disk would be a leak of exactly that.
    $this->assertModelMissing($attachment);
    expect(Spatie\MediaLibrary\MediaCollections\Models\Media::query()->whereKey($mediaId)->exists())->toBeFalse();
});

it('refuses to remove an attachment through another conversation', function () {
    $user = attachmentUser();
    $mine = FlowspecChat::factory()->for($user)->create();
    $other = FlowspecChat::factory()->for($user)->create();
    $attachment = FlowspecAttachment::factory()->for($other, 'chat')->create();

    // Scoped bindings: {attachment} must belong to {chat}.
    $this->actingAs($user)
        ->deleteJson(route('flowspec.attachments.destroy', [$mine, $attachment]))
        ->assertNotFound();

    $this->assertModelExists($attachment);
});

/*
|--------------------------------------------------------------------------
| Authorization
|--------------------------------------------------------------------------
*/

it('blocks another user from attaching to or reading a conversation\'s context', function () {
    $owner = attachmentUser();
    $stranger = attachmentUser();
    $chat = FlowspecChat::factory()->for($owner)->create();
    $attachment = FlowspecAttachment::factory()->for($chat, 'chat')->create();
    $page = documentedPage();

    $this->actingAs($stranger)
        ->postJson(route('flowspec.attachments.store', $chat), ['documents' => ["page:{$page->id}"]])
        ->assertForbidden();

    $this->actingAs($stranger)
        ->deleteJson(route('flowspec.attachments.destroy', [$chat, $attachment]))
        ->assertForbidden();
});

it('ignores a chat parameter on the picker that is not the caller\'s', function () {
    $owner = attachmentUser();
    $stranger = attachmentUser();
    $chat = FlowspecChat::factory()->for($owner)->create();
    $page = documentedPage();
    attachPage($chat, $page);

    // `?chat=` is untrusted input on a static route. Honoring it would leak
    // which documentation someone else's conversation has attached, through
    // what the picker declines to offer.
    $response = $this->actingAs($stranger)
        ->getJson(route('flowspec.attachments.picker', ['chat' => $chat->getKey()]))
        ->assertOk();

    expect($response->json('content'))->not->toContain('Já está no contexto');
});

/*
|--------------------------------------------------------------------------
| The picker and the suggestions
|--------------------------------------------------------------------------
*/

it('renders the picker grouped by solution, marking what is already attached', function () {
    $user = attachmentUser();
    $chat = FlowspecChat::factory()->for($user)->create();
    $solution = Solution::factory()->create(['name' => 'SVL']);
    $attached = DocumentationPage::factory()->for($solution, 'container')->create(['title' => 'Contrato', 'documentation' => 'x']);
    DocumentationPage::factory()->for($solution, 'container')->create(['title' => 'Payloads', 'documentation' => 'y']);
    DocumentationPage::factory()->for($solution, 'container')->create(['title' => 'Sem conteúdo', 'documentation' => null]);

    attachPage($chat, $attached);

    $content = $this->actingAs($user)
        ->getJson(route('flowspec.attachments.picker', ['chat' => $chat->getKey()]))
        ->assertOk()
        ->json('content');

    expect($content)
        ->toContain('SVL')
        ->toContain('Contrato')
        ->toContain('Payloads')
        ->toContain('Já está no contexto')
        // A page with no documentation would be a heading with nothing under it.
        ->not->toContain('Sem conteúdo');
});

it('suggests documentation for a system named in the message being typed', function () {
    $user = attachmentUser();
    $solution = Solution::factory()->create(['name' => 'SVL']);
    $page = DocumentationPage::factory()->for($solution, 'container')->create(['title' => 'Contrato', 'documentation' => 'x']);

    $this->actingAs($user)
        ->getJson(route('flowspec.documents.suggest', ['q' => 'preciso integrar com o SVL']))
        ->assertOk()
        ->assertJsonPath('suggestions.0.id', $page->id)
        ->assertJsonPath('suggestions.0.label', 'SVL — Contrato');
});

it('suggests nothing for a fragment too short to mean anything', function () {
    Solution::factory()->create(['name' => 'SVL']);

    $this->actingAs(attachmentUser())
        ->getJson(route('flowspec.documents.suggest', ['q' => 'sv']))
        ->assertOk()
        ->assertJson(['suggestions' => []]);
});

it('offers documentation stored in standalone groups, not only a solution\'s own pages', function () {
    $user = attachmentUser();
    $group = App\Models\DocumentationGroup::factory()->create(['name' => 'Integrações Digibee']);
    DocumentationPage::factory()->for($group, 'container')->create(['title' => 'Padrões de retry', 'documentation' => 'x']);

    // Almost every documented page in this inventory lives in an imported
    // GitBook space (a DocumentationGroup), not under a Solution. A picker that
    // listed only Solutions offered 4 of 617 pages.
    $content = $this->actingAs($user)
        ->getJson(route('flowspec.attachments.picker'))
        ->assertOk()
        ->json('content');

    expect($content)->toContain('Integrações Digibee')->toContain('Padrões de retry');
});

it('attaches a page from a standalone documentation group', function () {
    $user = attachmentUser();
    $chat = FlowspecChat::factory()->for($user)->create();
    $group = App\Models\DocumentationGroup::factory()->create(['name' => 'Integrações Digibee']);
    $page = DocumentationPage::factory()->for($group, 'container')->create(['title' => 'Padrões de retry', 'documentation' => 'usar backoff']);

    $this->actingAs($user)
        ->postJson(route('flowspec.attachments.store', $chat), ['documents' => ["page:{$page->id}"]])
        ->assertOk();

    expect($chat->attachments()->first()->label)->toBe('Integrações Digibee — Padrões de retry');

    $context = app(FlowspecContextResolver::class)->resolve($chat, 'gera');
    expect($context->pages->pluck('id')->all())->toBe([$page->id]);
});
