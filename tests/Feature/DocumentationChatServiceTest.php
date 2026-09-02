<?php

use App\Models\DocumentationChat;
use App\Models\DocumentationChatMessage;
use App\Models\DocumentationPage;
use App\Models\Notebook;
use App\Models\Solution;
use App\Models\User;
use App\Services\Documentation\ContextDocumentResolver;
use App\Services\Documentation\ContextPageResolver;
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
            parent::__construct(
                app(ContextDocumentResolver::class),
                app(ContextPageResolver::class),
                app(DocumentationChatPromptBuilder::class),
            );
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
        'notebook_id' => $page->notebook_id,
    ]);

    return $chat->messages()->create(array_merge([
        'role'              => 'user',
        'content'           => 'Documente o fluxo.',
        'existing_content'  => null,
        'context_media_ids' => [],
    ], $overrides));
}

it('creates documentation from scratch when the page is empty', function () {
    $notebook = Notebook::factory()->create();
    $page = DocumentationPage::factory()->for($notebook)->create(['documentation' => null]);

    $service = fakeChatService("## Objetivo\n\nTexto gerado.\n");
    $reply = $service->generate(chatMessageFor($page));

    expect($service->capturedPrompt)->toContain('A página está vazia')
        ->and($reply->content)->toContain('## Objetivo')
        ->and($reply->draft)->toBeNull() // no 4-backtick fence in this reply — purely conversational text
        ->and($reply->meta['model'])->toBe(config('services.documentation_ai.model'))
        ->and($reply->meta['tokens'])->toHaveKeys(['prompt', 'completion', 'cache_write', 'cache_read']);
});

it('extracts a 4-backtick draft block, keeping conversational text separate', function () {
    $notebook = Notebook::factory()->create();
    $page = DocumentationPage::factory()->for($notebook)->create();

    $reply = "Aqui está a documentação revisada.\n\n````\n# Título\n\nTexto.\n````\n";
    $result = fakeChatService($reply)->generate(chatMessageFor($page));

    expect($result->content)->toBe('Aqui está a documentação revisada.')
        ->and($result->draft)->toContain('# Título')
        ->and($result->draft)->not->toContain('````');
});

it('preserves a nested 3-backtick code block inside the draft', function () {
    $notebook = Notebook::factory()->create();
    $page = DocumentationPage::factory()->for($notebook)->create();

    $reply = "Pronto.\n\n````\n# Título\n\n```php\necho 1;\n```\n\nmais texto\n````\n";
    $result = fakeChatService($reply)->generate(chatMessageFor($page));

    expect($result->content)->toBe('Pronto.')
        ->and($result->draft)->toContain('```php')
        ->and($result->draft)->toContain('echo 1;')
        ->and($result->draft)->toContain('mais texto');
});

it('leaves draft null for a purely conversational reply', function () {
    $notebook = Notebook::factory()->create();
    $page = DocumentationPage::factory()->for($notebook)->create();

    $result = fakeChatService('Essa integração usa REST síncrono, conforme o protocolo cadastrado.')
        ->generate(chatMessageFor($page));

    expect($result->draft)->toBeNull()
        ->and($result->content)->toContain('REST síncrono');
});

it('includes the requirements checklist in the prompt', function () {
    $notebook = Notebook::factory()->create();
    $notebook->solutions()->attach(Solution::factory()->create(['environment' => null]));
    $page = DocumentationPage::factory()->for($notebook)->create();

    $service = fakeChatService('# ok');
    $service->generate(chatMessageFor($page));

    expect($service->capturedPrompt)->toContain('REQUISITOS MÍNIMOS');
});

it('includes the conversation history in the prompt', function () {
    $notebook = Notebook::factory()->create();
    $page = DocumentationPage::factory()->for($notebook)->create();
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
    $notebook = Notebook::factory()->create();
    $page = DocumentationPage::factory()->for($notebook)->create();

    $existing = str_repeat('a', 500000) . 'MARCADOR_FINAL';

    $service = fakeChatService('# ok');
    $service->generate(chatMessageFor($page, ['existing_content' => $existing]));

    expect($service->capturedPrompt)->toContain('MARCADOR_FINAL');
});

it('uses the existing content passed on the message', function () {
    $notebook = Notebook::factory()->create();
    $page = DocumentationPage::factory()->for($notebook)->create([
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
    $notebook = Notebook::factory()->create();
    $page = DocumentationPage::factory()->for($notebook)->create();

    // Real PDFs (~2KB each, magic bytes so the mime sniffs as application/pdf,
    // not text/*): the first fits (2009 <= 3000), the second blows past the cap.
    $pdf = "%PDF-1.4\n" . str_repeat("\x00", 2000);
    $first = $notebook->addMediaFromString($pdf)->usingFileName('a.pdf')->toMediaCollection(Notebook::CONTEXT_COLLECTION);
    $second = $notebook->addMediaFromString($pdf)->usingFileName('b.pdf')->toMediaCollection(Notebook::CONTEXT_COLLECTION);

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
    $notebook = Notebook::factory()->create();
    $page = DocumentationPage::factory()->for($notebook)->create();

    $notebook->addMediaFromString('conteúdo que NÃO deve ir ao modelo')
        ->usingFileName('notas.txt')
        ->toMediaCollection(Notebook::CONTEXT_COLLECTION);

    $service = fakeChatService('# ok');
    $reply = $service->generate(chatMessageFor($page, ['context_media_ids' => []]));

    expect($service->capturedPrompt)->not->toContain('conteúdo que NÃO deve ir ao modelo')
        ->and($service->capturedAttachments)->toBeEmpty()
        ->and($reply->meta['inlined'])->toBeEmpty();
});

it('inlines a selected text context document into the prompt', function () {
    Storage::fake('public');
    $notebook = Notebook::factory()->create();
    $page = DocumentationPage::factory()->for($notebook)->create();

    $media = $notebook
        ->addMediaFromString('SEGREDO: a ordem importa, BP antes do SVL')
        ->usingFileName('notas.txt')
        ->toMediaCollection(Notebook::CONTEXT_COLLECTION);

    $service = fakeChatService('# ok');
    $service->generate(chatMessageFor($page, ['context_media_ids' => [$media->id]]));

    expect($service->capturedPrompt)
        ->toContain('notas.txt')
        ->toContain('SEGREDO: a ordem importa')
        ->and($service->capturedAttachments)->toBe([]); // text goes into the prompt, not as an attachment
});

it('hides an opaque literal behind a marker instead of asking the model to copy it', function () {
    $notebook = Notebook::factory()->create();
    $page = DocumentationPage::factory()->for($notebook)->create();

    // Same shape as the SAP CPI Authorization header that exposed the bug —
    // synthetic, never a real credential.
    $token = base64_encode('sb-04938d98-2ea2-4495-835c-b8e028f11818!b38080|it-rt-btpprod-exemplo'
        . '!b106:aa793318-afe5-4231-825d-ddb5a3894178$oOOeDJVf3UKpiGNl2yfgZpl1b3ee4kKU9ozu3aqMU6s=');

    $service = fakeChatService('ok');
    $service->generate(chatMessageFor($page, [
        'content'          => 'Confira a autenticação de PRD.',
        'existing_content' => "## Auth\n\nAuthorization: Basic {$token}\n",
    ]));

    expect($service->capturedPrompt)->not->toContain($token)
        ->and($service->capturedPrompt)->toContain('[[LIT-1]]')
        ->and($service->capturedPrompt)->toContain('VALORES LITERAIS PROTEGIDOS')
        ->and($service->capturedPrompt)->toContain('começa com "' . substr($token, 0, 8) . '"');
});

it('restores the real value into the draft and the reply text', function () {
    $notebook = Notebook::factory()->create();
    $page = DocumentationPage::factory()->for($notebook)->create();

    $token = base64_encode('sb-04938d98-2ea2-4495-835c-b8e028f11818!b38080|it-rt-btpprod-exemplo'
        . '!b106:aa793318-afe5-4231-825d-ddb5a3894178$oOOeDJVf3UKpiGNl2yfgZpl1b3ee4kKU9ozu3aqMU6s=');

    $reply = "Mantive o token [[LIT-1]].\n\n````\n## Auth\n\n`Basic [[LIT-1]]`\n````\n";
    $result = fakeChatService($reply)->generate(chatMessageFor($page, [
        'existing_content' => "Authorization: Basic {$token}\n",
    ]));

    expect($result->draft)->toContain("`Basic {$token}`")
        ->and($result->draft)->not->toContain('[[LIT-')
        ->and($result->content)->toBe("Mantive o token {$token}.")
        ->and($result->meta['literals'])->toBe(['frozen' => 1, 'repaired' => 0, 'unresolved' => 0]);
});

it('repairs a token the model retyped from an unmasked source', function () {
    $notebook = Notebook::factory()->create();
    $page = DocumentationPage::factory()->for($notebook)->create();

    $token = base64_encode('sb-04938d98-2ea2-4495-835c-b8e028f11818!b38080|it-rt-btpprod-exemplo'
        . '!b106:aa793318-afe5-4231-825d-ddb5a3894178$oOOeDJVf3UKpiGNl2yfgZpl1b3ee4kKU9ozu3aqMU6s=');
    $mangled = substr($token, 0, -6) . 'N=';

    $result = fakeChatService("Pronto.\n\n````\n`Basic {$mangled}`\n````\n")
        ->generate(chatMessageFor($page, ['existing_content' => "Basic {$token}\n"]));

    expect($result->draft)->toContain($token)
        ->and($result->draft)->not->toContain($mangled)
        ->and($result->meta['literals']['repaired'])->toBe(1);
});

/*
|--------------------------------------------------------------------------
| Preserved blocks — images, files, embeds, diagram citations
|--------------------------------------------------------------------------
|
| The system prompt used to FORBID `<figure>`/`<img>`/`{% file %}`/`{% embed %}`
| outright while also demanding the complete page back, and the model resolved
| that contradiction by deleting the image already on the page — reported from
| the app while answering a request that had nothing to do with it. The model
| never sees these blocks now (BlockVault).
|
*/

/** A page whose text carries one of each block the model must not author. */
function pageWithBlocks(): DocumentationPage
{
    return DocumentationPage::factory()->for(Notebook::factory())->create([
        'documentation' => "# Fluxo\n\n"
            . "<figure><img src=\"/files/12\" alt=\"Topologia\"><figcaption>Topologia atual</figcaption></figure>\n\n"
            . "Texto que o usuário quer mudar.\n\n"
            . "{% file src=\"/files/13\" %}\n\n"
            . "{% diagram slug=\"zfl-bloqueio\" %}\n",
    ]);
}

it('never shows the model an image, a file card or a diagram citation', function () {
    $page = pageWithBlocks();
    $service = fakeChatService('Ok.');

    $service->generate(chatMessageFor($page, ['existing_content' => $page->documentation]));

    expect($service->capturedPrompt)
        ->not->toContain('<figure>')
        ->not->toContain('/files/12')
        ->not->toContain('zfl-bloqueio')
        ->toContain('[[BLOCK-1]]')
        ->toContain('[[BLOCK-3]]')
        // …and it is told what each marker stands for, and to keep it.
        ->toContain('BLOCOS PRESERVADOS')
        ->toContain('= imagem')
        ->toContain('= diagrama');
});

it('puts every block back into a draft that kept its markers', function () {
    $page = pageWithBlocks();

    $reply = "Reescrevi o texto.\n\n````\n# Fluxo\n\n[[BLOCK-1]]\n\nTexto novo.\n\n[[BLOCK-2]]\n\n[[BLOCK-3]]\n````\n";
    $result = fakeChatService($reply)->generate(chatMessageFor($page, ['existing_content' => $page->documentation]));

    expect($result->draft)
        ->toContain('<figure><img src="/files/12" alt="Topologia"><figcaption>Topologia atual</figcaption></figure>')
        ->toContain('{% file src="/files/13" %}')
        ->toContain('{% diagram slug="zfl-bloqueio" %}')
        ->not->toContain('[[BLOCK-')
        ->and($result->meta['blocks'])->toBe(['frozen' => 3, 'dropped' => 0]);
});

it('warns the person when a draft came back without a block, instead of letting them find out in the diff', function () {
    $page = pageWithBlocks();

    // The reported turn: the text changes and the image quietly does not come back.
    $reply = "Reescrevi o texto.\n\n````\n# Fluxo\n\nTexto novo.\n\n[[BLOCK-2]]\n\n[[BLOCK-3]]\n````\n";
    $result = fakeChatService($reply)->generate(chatMessageFor($page, ['existing_content' => $page->documentation]));

    expect($result->meta['blocks'])->toBe(['frozen' => 3, 'dropped' => 1])
        ->and($result->content)->toContain('não inclui 1 bloco')
        ->and($result->content)->toContain('revise o rascunho antes de aplicar')
        // The rest of the draft is still delivered — the person decides.
        ->and($result->draft)->toContain('{% file src="/files/13" %}')
        ->and($result->draft)->not->toContain('<figure>');
});

it('counts a marker the model only mentioned in prose as dropped', function () {
    $page = pageWithBlocks();

    // The audit runs on the DRAFT, not on the whole reply, precisely for this.
    $reply = "Removi o [[BLOCK-1]] porque não parecia relacionado.\n\n````\n# Fluxo\n\nTexto novo.\n\n[[BLOCK-2]]\n\n[[BLOCK-3]]\n````\n";
    $result = fakeChatService($reply)->generate(chatMessageFor($page, ['existing_content' => $page->documentation]));

    expect($result->meta['blocks']['dropped'])->toBe(1);
});

it('says nothing about blocks when the page has none', function () {
    $page = DocumentationPage::factory()->for(Notebook::factory())->create(['documentation' => "# Só texto\n"]);

    $result = fakeChatService("Ok.\n\n````\n# Só texto\n\nMais texto.\n````\n")
        ->generate(chatMessageFor($page, ['existing_content' => $page->documentation]));

    expect($result->meta['blocks'])->toBe(['frozen' => 0, 'dropped' => 0])
        ->and($result->content)->not->toContain('Atenção');
});

it('keeps an image frozen even when it sits inside a figure with an id-bearing src', function () {
    // The <img> inside a <figure> must not be frozen twice: the figure is
    // captured first and the image pattern can no longer see inside it.
    $page = DocumentationPage::factory()->for(Notebook::factory())->create([
        'documentation' => "<figure><img src=\"/files/99\"></figure>\n\nTexto.\n",
    ]);

    $result = fakeChatService("Ok.\n\n````\n[[BLOCK-1]]\n\nTexto novo.\n````\n")
        ->generate(chatMessageFor($page, ['existing_content' => $page->documentation]));

    expect($result->meta['blocks'])->toBe(['frozen' => 1, 'dropped' => 0])
        ->and($result->draft)->toContain('<figure><img src="/files/99"></figure>');
});
