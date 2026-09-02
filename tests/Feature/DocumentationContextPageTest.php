<?php

use App\Enums\UserRole;
use App\Models\DocumentationChat;
use App\Models\DocumentationChatMessage;
use App\Models\DocumentationPage;
use App\Models\Notebook;
use App\Models\User;
use App\Services\Documentation\ContextDocumentResolver;
use App\Services\Documentation\ContextPageResolver;
use App\Services\Documentation\DocumentationChatPromptBuilder;
use App\Services\Documentation\DocumentationChatService;
use Illuminate\Foundation\Testing\LazilyRefreshDatabase;
use Illuminate\Support\Facades\Queue;
use Laravel\Ai\Responses\AgentResponse;
use Laravel\Ai\Responses\Data\Meta;
use Laravel\Ai\Responses\Data\Usage;

uses(LazilyRefreshDatabase::class);

/*
|--------------------------------------------------------------------------
| Context PAGES for the Documentation Assistant
|--------------------------------------------------------------------------
|
| Other documentation pages, handed to one chat turn as reference. Distinct
| from the caderno's uploaded context documents in three ways this file pins:
| they may come from ANY caderno, they are inlined as text, and they are
| recorded on the message rather than on the caderno.
*/

/** Captures the prompt instead of calling the provider. Local to this file on purpose (see the parallel-suite note in AGENTS.md). */
function fakeContextPageService(string $reply = 'ok'): DocumentationChatService
{
    return new class($reply) extends DocumentationChatService
    {
        public string $capturedPrompt = '';

        public function __construct(private string $reply)
        {
            parent::__construct(
                app(ContextDocumentResolver::class),
                app(ContextPageResolver::class),
                app(DocumentationChatPromptBuilder::class),
            );
        }

        protected function prompt(string $prompt, array $attachments = []): AgentResponse
        {
            $this->capturedPrompt = $prompt;

            return new AgentResponse('fake', $this->reply, new Usage(1, 1), new Meta('anthropic', 'claude-sonnet-5'));
        }
    };
}

function contextPageMessage(DocumentationPage $page, array $pageIds): DocumentationChatMessage
{
    $chat = DocumentationChat::create([
        'user_id'     => User::factory()->create()->id,
        'target_type' => $page->getMorphClass(),
        'target_id'   => $page->getKey(),
        'notebook_id' => $page->notebook_id,
    ]);

    return $chat->messages()->create([
        'role'              => 'user',
        'content'           => 'Documente a integração.',
        'existing_content'  => 'Conteúdo atual da página.',
        'context_media_ids' => [],
        'context_page_ids'  => $pageIds,
    ]);
}

function ctxAdmin(): User
{
    return User::factory()->create(['role' => UserRole::Admin->value]);
}

/*
|--------------------------------------------------------------------------
| The prompt
|--------------------------------------------------------------------------
*/

it('inlines a chosen page under its own heading, naming the caderno it came from', function () {
    $notebook = Notebook::factory()->create(['name' => 'Vendas']);
    $page = DocumentationPage::factory()->for($notebook)->create();

    $elsewhere = Notebook::factory()->create(['name' => 'SAP ECC']);
    $reference = DocumentationPage::factory()->for($elsewhere)->create([
        'title'         => 'Fila de pedidos',
        'documentation' => 'O ECC publica cada pedido na fila ZPED.',
    ]);

    $service = fakeContextPageService();
    $reply = $service->generate(contextPageMessage($page, [$reference->id]));

    expect($service->capturedPrompt)
        ->toContain('PÁGINAS DE CONTEXTO')
        ->toContain('### Página: Fila de pedidos (caderno: SAP ECC)')
        ->toContain('O ECC publica cada pedido na fila ZPED.')
        // The heading has to say what these are NOT: the draft replaces only
        // the current page, and a model handed two bodies of text can return
        // the wrong one.
        ->toContain('NÃO são a página que você está escrevendo')
        ->and($reply->meta['context_pages'])->toBe(['Fila de pedidos'])
        ->and($reply->meta['omitted_pages'])->toBe([]);
});

it('masks a context page protected values', function () {
    $notebook = Notebook::factory()->create();
    $page = DocumentationPage::factory()->for($notebook)->create();
    $reference = DocumentationPage::factory()->for($notebook)->create([
        'documentation' => 'Header: {% secret %}s3nh4-de-verdade{% endsecret %}',
    ]);

    $service = fakeContextPageService();
    $service->generate(contextPageMessage($page, [$reference->id]));

    // The fifth surface that hands a page's text to somebody — and the only one
    // where the page read is not the page being edited, so a value an editor
    // may not see in caderno A must not be quotable into caderno B.
    expect($service->capturedPrompt)
        ->not->toContain('s3nh4-de-verdade')
        ->toContain('[[SECRET-1]]');
});

it('strips a context page media and diagram blocks instead of freezing them', function () {
    $notebook = Notebook::factory()->create();
    $page = DocumentationPage::factory()->for($notebook)->create();
    $reference = DocumentationPage::factory()->for($notebook)->create([
        'documentation' => "Antes.\n\n<figure><img src=\"/files/77\"></figure>\n\n{% diagram slug=\"fluxo-x\" %}\n\nDepois.",
    ]);

    $service = fakeContextPageService();
    $service->generate(contextPageMessage($page, [$reference->id]));

    // Never `[[BLOCK-n]]`: a marker is an instruction to keep the block, and
    // these blocks belong to a different page. Never the raw syntax either —
    // the model can copy a `/files/{id}` it is shown.
    expect($service->capturedPrompt)
        ->not->toContain('/files/77')
        ->not->toContain('slug="fluxo-x"')
        ->toContain('[imagem]')
        ->toContain('[diagrama]');
});

it('never lets the page being written be its own context', function () {
    $notebook = Notebook::factory()->create();
    $page = DocumentationPage::factory()->for($notebook)->create([
        'title'         => 'A própria página',
        'documentation' => 'Texto da própria página.',
    ]);

    $service = fakeContextPageService();
    $reply = $service->generate(contextPageMessage($page, [$page->id]));

    expect($service->capturedPrompt)->not->toContain('PÁGINAS DE CONTEXTO (outras')
        ->and($reply->meta['context_pages'])->toBe([]);
});

it('omits pages past the cap and says which ones', function () {
    config()->set('services.documentation_ai.max_context_pages', 2);

    $notebook = Notebook::factory()->create();
    $page = DocumentationPage::factory()->for($notebook)->create();

    $references = collect(['Primeira', 'Segunda', 'Terceira'])->map(
        fn (string $title) => DocumentationPage::factory()->for($notebook)->create([
            'title'         => $title,
            'documentation' => "Conteúdo de {$title}.",
        ]),
    );

    $service = fakeContextPageService();
    $reply = $service->generate(contextPageMessage($page, $references->pluck('id')->all()));

    expect($reply->meta['context_pages'])->toBe(['Primeira', 'Segunda'])
        // Flagged, never dropped in silence: somebody picked them by hand.
        ->and($reply->meta['omitted_pages'])->toBe(['Terceira'])
        ->and($service->capturedPrompt)->not->toContain('Conteúdo de Terceira.');
});

it('truncates a context page that exceeds the budget', function () {
    config()->set('services.documentation_ai.page_budget_chars', 60);

    $notebook = Notebook::factory()->create();
    $page = DocumentationPage::factory()->for($notebook)->create();
    $reference = DocumentationPage::factory()->for($notebook)->create([
        'documentation' => str_repeat('a', 500),
    ]);

    $service = fakeContextPageService();
    $service->generate(contextPageMessage($page, [$reference->id]));

    expect($service->capturedPrompt)->toContain('[página truncada]')
        ->and($service->capturedPrompt)->not->toContain(str_repeat('a', 100));
});

it('keeps the order the pages were picked in', function () {
    $notebook = Notebook::factory()->create();
    $page = DocumentationPage::factory()->for($notebook)->create();

    $first = DocumentationPage::factory()->for($notebook)->create(['title' => 'Alfa', 'documentation' => 'A.']);
    $second = DocumentationPage::factory()->for($notebook)->create(['title' => 'Beta', 'documentation' => 'B.']);

    $service = fakeContextPageService();
    // Picked newest first — `whereIn` would answer in id order.
    $reply = $service->generate(contextPageMessage($page, [$second->id, $first->id]));

    expect($reply->meta['context_pages'])->toBe(['Beta', 'Alfa']);
});

it('ignores a chosen page that has no content', function () {
    $notebook = Notebook::factory()->create();
    $page = DocumentationPage::factory()->for($notebook)->create();
    $empty = DocumentationPage::factory()->for($notebook)->create(['documentation' => null]);

    $service = fakeContextPageService();
    $reply = $service->generate(contextPageMessage($page, [$empty->id]));

    expect($reply->meta['context_pages'])->toBe([])
        ->and($service->capturedPrompt)->not->toContain('PÁGINAS DE CONTEXTO (outras');
});

/*
|--------------------------------------------------------------------------
| The endpoint and the panel
|--------------------------------------------------------------------------
*/

it('groups the context page catalog by caderno, current one first', function () {
    $current = Notebook::factory()->create(['name' => 'Zzz caderno atual']);
    $page = DocumentationPage::factory()->for($current)->create(['documentation' => 'Texto.']);

    $other = Notebook::factory()->create(['name' => 'Aaa outro caderno']);
    DocumentationPage::factory()->for($other)->create(['documentation' => 'Texto.']);

    // A caderno with nothing written in it contributes no group at all: an
    // empty page offered as context offers a title.
    Notebook::factory()->create(['name' => 'Bbb vazio']);
    DocumentationPage::factory()->for(Notebook::first())->create(['documentation' => null]);

    $response = $this->actingAs(ctxAdmin())
        ->getJson(route('notebooks.context-pages', $current))
        ->assertOk();

    $groups = collect($response->json('groups'));

    expect($groups->pluck('notebook')->first())->toBe('Zzz caderno atual')
        ->and($groups->first()['current'])->toBeTrue()
        ->and($groups->pluck('notebook')->all())->not->toContain('Bbb vazio')
        ->and($groups->pluck('notebook')->all())->toContain('Aaa outro caderno');

    expect(collect($groups->first()['pages'])->pluck('title')->all())->toBe([$page->title]);
});

it('refuses the context page catalog to someone who cannot edit the caderno', function () {
    $notebook = Notebook::factory()->create();

    $this->actingAs(User::factory()->create(['role' => UserRole::Viewer->value]))
        ->getJson(route('notebooks.context-pages', $notebook))
        ->assertForbidden();
});

it('renders the context page picker in the chat panel', function () {
    $notebook = Notebook::factory()->create();
    $page = DocumentationPage::factory()->for($notebook)->create();

    $response = $this->actingAs(ctxAdmin())
        ->getJson(route('notebooks.chat.panel', [$notebook, $page]))
        ->assertOk();

    expect($response->json('content'))
        ->toContain('Páginas de contexto')
        ->toContain('data-ak-context-pages')
        ->toContain(route('notebooks.context-pages', $notebook));
});

it('records the chosen pages on the message', function () {
    // The reply job would otherwise run on the sync queue and reach the real
    // provider — and its own assistant message would be the latest row.
    Queue::fake();

    $notebook = Notebook::factory()->create();
    $page = DocumentationPage::factory()->for($notebook)->create();
    $reference = DocumentationPage::factory()->for($notebook)->create(['documentation' => 'Texto.']);

    $this->actingAs(ctxAdmin())
        ->postJson(route('notebooks.chat.messages.store', [$notebook, $page]), [
            'message'  => 'Compare com a outra página.',
            'page_ids' => [$reference->id],
        ])
        ->assertOk();

    expect(DocumentationChatMessage::query()->where('role', 'user')->sole()->context_page_ids)
        ->toBe([$reference->id]);
});

it('refuses a page id that does not exist', function () {
    Queue::fake();

    $notebook = Notebook::factory()->create();
    $page = DocumentationPage::factory()->for($notebook)->create();

    $response = $this->actingAs(ctxAdmin())
        ->postJson(route('notebooks.chat.messages.store', [$notebook, $page]), [
            'message'  => 'Oi.',
            'page_ids' => [999999],
        ])
        ->assertStatus(422)
        ->assertJson(['type' => 'warning']);

    // This app reformats every ValidationException to {message,title,type} —
    // there is no `errors` key to assert against (see AGENTS.md).
    expect($response->json('message'))->not->toBeEmpty();
});
