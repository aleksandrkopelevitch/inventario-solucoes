<?php

use App\Models\DocumentationChat;
use App\Models\DocumentationChatMessage;
use App\Models\DocumentationPage;
use App\Models\Solution;
use App\Models\User;
use App\Services\Documentation\ContextDocumentResolver;
use App\Services\Documentation\DocumentationChatPromptBuilder;
use App\Services\Documentation\DocumentationChatService;
use Illuminate\Foundation\Testing\LazilyRefreshDatabase;
use Illuminate\Support\Facades\Storage;
use Laravel\Ai\Responses\AgentResponse;
use Laravel\Ai\Responses\Data\Meta;
use Laravel\Ai\Responses\Data\Usage;

uses(LazilyRefreshDatabase::class);

/** Test double for the service: captures the prompt/attachments and returns scripted text instead of calling the API. */
function fakeChatService(string $reply): DocumentationChatService
{
    return new class($reply) extends DocumentationChatService
    {
        public string $capturedPrompt = '';

        public array $capturedAttachments = [];

        public function __construct(private string $reply)
        {
            parent::__construct(app(ContextDocumentResolver::class), app(DocumentationChatPromptBuilder::class));
        }

        protected function prompt(string $prompt, array $attachments = []): AgentResponse
        {
            $this->capturedPrompt = $prompt;
            $this->capturedAttachments = $attachments;

            return new AgentResponse('fake', $this->reply, new Usage(10, 20), new Meta('anthropic', 'claude-sonnet-5'));
        }
    };
}

function chatMessageFor(DocumentationPage $page, array $overrides = []): DocumentationChatMessage
{
    $chat = DocumentationChat::create([
        'user_id'     => User::factory()->create()->id,
        'target_type' => $page->getMorphClass(),
        'target_id'   => $page->getKey(),
        'solution_id' => $page->container_id,
    ]);

    return $chat->messages()->create(array_merge([
        'role'              => 'user',
        'content'           => 'Documente o fluxo.',
        'existing_content'  => null,
        'context_media_ids' => [],
    ], $overrides));
}

it('creates documentation from scratch when the page is empty', function () {
    $solution = Solution::factory()->create();
    $page = DocumentationPage::factory()->for($solution, 'container')->create(['documentation' => null]);

    $service = fakeChatService("## Objetivo\n\nTexto gerado.\n");
    $reply = $service->generate(chatMessageFor($page));

    expect($service->capturedPrompt)->toContain('A página está vazia')
        ->and($reply->content)->toContain('## Objetivo')
        ->and($reply->draft)->toBeNull() // no 4-backtick fence in this reply — purely conversational text
        ->and($reply->meta['model'])->toBe(config('services.documentation_ai.model'))
        ->and($reply->meta['tokens'])->toHaveKeys(['prompt', 'completion', 'cache_write', 'cache_read']);
});

it('extracts a 4-backtick draft block, keeping conversational text separate', function () {
    $solution = Solution::factory()->create();
    $page = DocumentationPage::factory()->for($solution, 'container')->create();

    $reply = "Aqui está a documentação revisada.\n\n````\n# Título\n\nTexto.\n````\n";
    $result = fakeChatService($reply)->generate(chatMessageFor($page));

    expect($result->content)->toBe('Aqui está a documentação revisada.')
        ->and($result->draft)->toContain('# Título')
        ->and($result->draft)->not->toContain('````');
});

it('preserves a nested 3-backtick code block inside the draft', function () {
    $solution = Solution::factory()->create();
    $page = DocumentationPage::factory()->for($solution, 'container')->create();

    $reply = "Pronto.\n\n````\n# Título\n\n```php\necho 1;\n```\n\nmais texto\n````\n";
    $result = fakeChatService($reply)->generate(chatMessageFor($page));

    expect($result->content)->toBe('Pronto.')
        ->and($result->draft)->toContain('```php')
        ->and($result->draft)->toContain('echo 1;')
        ->and($result->draft)->toContain('mais texto');
});

it('leaves draft null for a purely conversational reply', function () {
    $solution = Solution::factory()->create();
    $page = DocumentationPage::factory()->for($solution, 'container')->create();

    $result = fakeChatService('Essa integração usa REST síncrono, conforme o protocolo cadastrado.')
        ->generate(chatMessageFor($page));

    expect($result->draft)->toBeNull()
        ->and($result->content)->toContain('REST síncrono');
});

it('includes the requirements checklist in the prompt', function () {
    $solution = Solution::factory()->create(['environment' => null]);
    $page = DocumentationPage::factory()->for($solution, 'container')->create();

    $service = fakeChatService('# ok');
    $service->generate(chatMessageFor($page));

    expect($service->capturedPrompt)->toContain('REQUISITOS MÍNIMOS');
});

it('includes the conversation history in the prompt', function () {
    $solution = Solution::factory()->create();
    $page = DocumentationPage::factory()->for($solution, 'container')->create();
    $first = chatMessageFor($page, ['content' => 'Descreva a integração.']);
    $first->chat->messages()->create(['role' => 'assistant', 'content' => 'Pronto, descrevi a integração.']);
    $second = $first->chat->messages()->create(['role' => 'user', 'content' => 'Agora adicione uma seção de erros.']);

    $service = fakeChatService('# ok');
    $service->generate($second);

    expect($service->capturedPrompt)
        ->toContain('HISTÓRICO DA CONVERSA')
        ->toContain('Descreva a integração.')
        ->toContain('Pronto, descrevi a integração.');
});

it('sends the full existing content to the model without truncating', function () {
    // The draft replaces the whole page in the editor, so the current content
    // can't be cut off — the model needs to see everything to rewrite without
    // losing the tail. A marker at the end proves nothing was discarded.
    $solution = Solution::factory()->create();
    $page = DocumentationPage::factory()->for($solution, 'container')->create();

    $existing = str_repeat('a', 500000) . 'MARCADOR_FINAL';

    $service = fakeChatService('# ok');
    $service->generate(chatMessageFor($page, ['existing_content' => $existing]));

    expect($service->capturedPrompt)->toContain('MARCADOR_FINAL');
});

it('uses the existing content passed on the message', function () {
    $solution = Solution::factory()->create();
    $page = DocumentationPage::factory()->for($solution, 'container')->create([
        'documentation' => '# Conteúdo antigo (irrelevante — a mensagem carrega o snapshot)',
    ]);

    $service = fakeChatService('# Novo');
    $service->generate(chatMessageFor($page, ['existing_content' => '# Conteúdo atual do editor']));

    expect($service->capturedPrompt)
        ->toContain('CONTEÚDO ATUAL DA PÁGINA')
        ->toContain('Conteúdo atual do editor');
});

it('omits native attachments beyond the aggregate byte budget', function () {
    Storage::fake('public');
    config()->set('services.documentation_ai.max_attachment_bytes', 3000);
    $solution = Solution::factory()->create();
    $page = DocumentationPage::factory()->for($solution, 'container')->create();

    // Real PDFs (~2KB each, magic bytes so the mime sniffs as application/pdf,
    // not text/*): the first fits (2009 <= 3000), the second blows past the cap.
    $pdf = "%PDF-1.4\n" . str_repeat("\x00", 2000);
    $first = $solution->addMediaFromString($pdf)->usingFileName('a.pdf')->toMediaCollection(Solution::CONTEXT_COLLECTION);
    $second = $solution->addMediaFromString($pdf)->usingFileName('b.pdf')->toMediaCollection(Solution::CONTEXT_COLLECTION);

    $service = fakeChatService('# ok');
    $reply = $service->generate(chatMessageFor($page, ['context_media_ids' => [$first->id, $second->id]]));

    expect($service->capturedAttachments)->toHaveCount(1)
        ->and($reply->meta['attached'])->toHaveCount(1)
        ->and($reply->meta['omitted_attachments'])->toBe(['b.pdf']);
});

it('sends no context documents when the user unchecked all of them', function () {
    // The panel's checkboxes come checked by default, so `[]` only happens
    // when the user unchecked all of them — it can't mean "all".
    Storage::fake('public');
    $solution = Solution::factory()->create();
    $page = DocumentationPage::factory()->for($solution, 'container')->create();

    $solution->addMediaFromString('conteúdo que NÃO deve ir ao modelo')
        ->usingFileName('notas.txt')
        ->toMediaCollection(Solution::CONTEXT_COLLECTION);

    $service = fakeChatService('# ok');
    $reply = $service->generate(chatMessageFor($page, ['context_media_ids' => []]));

    expect($service->capturedPrompt)->not->toContain('conteúdo que NÃO deve ir ao modelo')
        ->and($service->capturedAttachments)->toBeEmpty()
        ->and($reply->meta['inlined'])->toBeEmpty();
});

it('inlines a selected text context document into the prompt', function () {
    Storage::fake('public');
    $solution = Solution::factory()->create();
    $page = DocumentationPage::factory()->for($solution, 'container')->create();

    $media = $solution
        ->addMediaFromString('SEGREDO: a ordem importa, BP antes do SVL')
        ->usingFileName('notas.txt')
        ->toMediaCollection(Solution::CONTEXT_COLLECTION);

    $service = fakeChatService('# ok');
    $service->generate(chatMessageFor($page, ['context_media_ids' => [$media->id]]));

    expect($service->capturedPrompt)
        ->toContain('notas.txt')
        ->toContain('SEGREDO: a ordem importa')
        ->and($service->capturedAttachments)->toBe([]); // text goes into the prompt, not as an attachment
});
