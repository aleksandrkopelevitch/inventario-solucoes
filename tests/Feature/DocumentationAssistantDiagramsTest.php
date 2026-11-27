<?php

use App\Models\DocumentationChat;
use App\Models\DocumentationChatMessage;
use App\Models\DocumentationPage;
use App\Models\Notebook;
use App\Models\User;
use App\Services\Documentation\AssistantDiagramRequests;
use App\Services\Documentation\ContextDocumentResolver;
use App\Services\Documentation\ContextPageResolver;
use App\Services\Documentation\DocumentationChatPromptBuilder;
use App\Services\Documentation\DocumentationChatService;
use App\Services\Documentation\UmlDraftPromptBuilder;
use App\Services\Documentation\UmlDraftService;
use App\Support\Documentation\UmlSolutionBindings;
use App\Support\GitbookRenderer;
use Illuminate\Foundation\Testing\LazilyRefreshDatabase;
use Laravel\Ai\Responses\AgentResponse;
use Laravel\Ai\Responses\Data\Meta;
use Laravel\Ai\Responses\Data\Usage;

uses(LazilyRefreshDatabase::class);

/*
 * The Documentation Assistant asking for UML diagrams in its draft
 * (`{% diagrama %}`), each made by UmlDraftService — AssistantDiagramRequests.
 */

/** The diagram model, scripted: one reply per call, prompts captured. */
function assistantUml(string ...$replies): UmlDraftService
{
    $service = new class($replies) extends UmlDraftService
    {
        /** @var list<array{type: string, prompt: string}> */
        public array $calls = [];

        /** @param list<string> $replies */
        public function __construct(private array $replies)
        {
            parent::__construct(app(UmlDraftPromptBuilder::class), app(UmlSolutionBindings::class));
        }

        protected function prompt(string $type, string $prompt): AgentResponse
        {
            $this->calls[] = ['type' => $type, 'prompt' => $prompt];

            return new AgentResponse('fake', array_shift($this->replies) ?? '', new Usage(10, 20), new Meta('gemini', 'gemini-3.8-flash'));
        }
    };
    app()->instance(UmlDraftService::class, $service);

    return $service;
}

/** The assistant, scripted with one reply. */
function assistantReplying(string $reply): DocumentationChatService
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
                app(AssistantDiagramRequests::class),
            );
        }

        protected function prompt(string $prompt, array $attachments = []): AgentResponse
        {
            $this->capturedPrompt = $prompt;

            return new AgentResponse('fake', $this->reply, new Usage(10, 20), new Meta('gemini', 'gemini-3.8-flash'));
        }
    };
}

function assistantTurn(DocumentationPage $page, string $content = 'Desenhe o fluxo.'): DocumentationChatMessage
{
    $chat = DocumentationChat::create([
        'user_id'     => User::factory()->create()->id,
        'target_type' => $page->getMorphClass(),
        'target_id'   => $page->getKey(),
        'notebook_id' => $page->notebook_id,
    ]);

    return $chat->messages()->create([
        'role'              => 'user',
        'content'           => $content,
        'existing_content'  => $page->documentation,
        'context_media_ids' => [],
    ]);
}

function assistantPage(string $documentation): DocumentationPage
{
    return DocumentationPage::factory()->for(Notebook::factory()->create())->create(['documentation' => $documentation]);
}

function componentsJson(string $first = 'VTEX', array $at = [0, 0]): string
{
    return "```json\n" . json_encode([
        'nodes' => [
            ['id' => 'a', 'kind' => 'component', 'name' => $first, 'at' => $at],
            ['id' => 'b', 'kind' => 'component', 'name' => 'SAP', 'at' => [1, 0]],
        ],
        'edges' => [['from' => 'a', 'to' => 'b', 'kind' => 'directed', 'label' => 'pedido']],
    ], JSON_UNESCAPED_UNICODE) . "\n```";
}

/** A draft reply wrapping $body in the 4-backtick fence. */
function draftReply(string $body, string $say = 'Montei a página e pedi o diagrama — confira no rascunho.'): string
{
    return "{$say}\n\n````\n{$body}\n````\n";
}

it('turns a diagram request into a UML block the page draws, grounded on the draft', function () {
    $uml = assistantUml(componentsJson());
    $page = assistantPage('Texto antigo.');

    $reply = assistantReplying(draftReply(
        "## Integração\n\nO VTEX envia o pedido ao SAP.\n\n"
        . "{% diagrama tipo=\"component\" titulo=\"Pedido &quot;VTEX&quot;\" %}\nVTEX chama o SAP com o pedido\n{% enddiagrama %}\n\nFim."
    ))->generate(assistantTurn($page));

    // Written the way the editor writes it: `"` in an attribute as `&quot;`.
    expect($reply->draft)->toContain("{% uml type=\"component\" title=\"Pedido &quot;VTEX&quot;\" %}\n")
        ->and($reply->draft)->toContain('"name": "VTEX"')
        ->and($reply->draft)->toContain("{% enduml %}\n\nFim.")
        ->and($reply->draft)->not->toContain('{% diagrama')
        ->and($reply->content)->toBe('Montei a página e pedi o diagrama — confira no rascunho.')
        ->and($reply->meta['diagrams'])->toBe(['requested' => 1, 'generated' => 1, 'failed' => 0])
        // Drawn from what the assistant just WROTE, with its description as the request.
        ->and($uml->calls[0]['type'])->toBe('component')
        ->and($uml->calls[0]['prompt'])->toContain('O VTEX envia o pedido ao SAP.')
        ->and($uml->calls[0]['prompt'])->toContain('VTEX chama o SAP com o pedido')
        ->and($uml->calls[0]['prompt'])->not->toContain('Texto antigo.');

    expect(app(GitbookRenderer::class)->problems($reply->draft))->toBe([]);
});

it('changes a diagram already on the page, keeping its type and title, and counts nothing as dropped', function () {
    $uml = assistantUml(componentsJson('Loja VTEX'));
    $page = assistantPage("Antes.\n\n{% uml type=\"component\" title=\"Pedidos\" %}\n{\"nodes\": [{\"id\": \"antigo\", \"kind\": \"component\", \"name\": \"Antigo\", \"at\": [0, 0]}]}\n{% enduml %}\n\nDepois.");

    $service = assistantReplying(draftReply("Antes.\n\n{% diagrama altera=\"[[BLOCK-1]]\" %}troque o Antigo pela Loja VTEX{% enddiagrama %}\n\nDepois."));
    $reply = $service->generate(assistantTurn($page));

    // The assistant was told which marker is which diagram.
    expect($service->capturedPrompt)->toContain('[[BLOCK-1]] = diagrama UML (Diagrama de componentes — "Pedidos")')
        ->and($uml->calls[0]['type'])->toBe('component')
        ->and($uml->calls[0]['prompt'])->toContain('DIAGRAMA ATUAL')
        ->and($uml->calls[0]['prompt'])->toContain('"id": "antigo"')
        ->and($reply->draft)->toContain('{% uml type="component" title="Pedidos" %}')
        ->and($reply->draft)->toContain('Loja VTEX')
        ->and($reply->draft)->not->toContain('Antigo')
        ->and($reply->content)->not->toContain('não inclui')
        ->and($reply->meta['blocks']['dropped'])->toBe(0);
});

it('keeps the original diagram when a change fails, and says so', function () {
    assistantUml('Não consigo.');
    $original = "{% uml type=\"component\" title=\"Pedidos\" %}\n{\"nodes\": [{\"id\": \"antigo\", \"kind\": \"component\", \"name\": \"Antigo\", \"at\": [0, 0]}]}\n{% enduml %}";
    $page = assistantPage("Antes.\n\n{$original}");

    $reply = assistantReplying(draftReply("Antes.\n\n{% diagrama altera=\"[[BLOCK-1]]\" %}mude algo{% enddiagrama %}"))
        ->generate(assistantTurn($page));

    expect($reply->draft)->toContain($original)
        ->and($reply->content)->toContain('⚠️ Diagrama "Pedidos": O especialista não devolveu um diagrama legível')
        ->and($reply->content)->toContain('O diagrama anterior foi mantido.')
        ->and($reply->meta['diagrams']['failed'])->toBe(1);
});

it('leaves out a new diagram that fails, and says so', function () {
    assistantUml("```json\n{\"error\": \"Nada a desenhar.\"}\n```");
    $page = assistantPage('Texto.');

    $reply = assistantReplying(draftReply("Texto.\n\n{% diagrama tipo=\"sequence\" titulo=\"Fluxo\" %}\nalgo\n{% enddiagrama %}\n\nFim."))
        ->generate(assistantTurn($page));

    expect($reply->draft)->toBe("Texto.\n\nFim.\n")
        ->and($reply->content)->toContain('⚠️ Diagrama "Fluxo": Não dá para montar este diagrama: Nada a desenhar.');
});

it('honours at most MAX_PER_TURN requests, an unknown type and nothing inside a hint', function () {
    $uml = assistantUml(componentsJson(), componentsJson(), componentsJson());
    $page = assistantPage('Texto.');

    $reply = assistantReplying(draftReply(implode("\n\n", [
        "{% hint style=\"info\" %}\n{% diagrama tipo=\"component\" titulo=\"No destaque\" %}x{% enddiagrama %}\n{% endhint %}",
        '{% diagrama tipo="mermaid" titulo="Errado" %}x{% enddiagrama %}',
        '{% diagrama tipo="component" titulo="Terceiro" %}x{% enddiagrama %}',
    ])))->generate(assistantTurn($page));

    expect($uml->calls)->toBe([])
        ->and($reply->content)->toContain('Diagrama "No destaque": estava dentro de um destaque')
        ->and($reply->content)->toContain('Diagrama "Errado": o tipo "mermaid" não existe')
        ->and($reply->content)->toContain('Diagrama "Terceiro": passou do limite de ' . AssistantDiagramRequests::MAX_PER_TURN)
        ->and($reply->draft)->not->toContain('{% diagrama')
        ->and($reply->draft)->not->toContain('{% uml');
});

it('starts no diagram the job would not have time to finish', function () {
    $uml = assistantUml(componentsJson());
    config(['services.documentation_ai.uml_timeout' => 10000]);
    $page = assistantPage('Texto.');

    $reply = assistantReplying(draftReply('{% diagrama tipo="component" titulo="Tarde" %}x{% enddiagrama %}'))
        ->generate(assistantTurn($page));

    expect($uml->calls)->toBe([])
        ->and($reply->content)->toContain('Diagrama "Tarde": não houve tempo');
});

it('keeps an image whose marker was asked to change like a diagram', function () {
    assistantUml(componentsJson());
    $page = assistantPage("<figure><img src=\"/files/12\" alt=\"x\"></figure>\n\nTexto.");

    $reply = assistantReplying(draftReply("{% diagrama altera=\"[[BLOCK-1]]\" %}mude{% enddiagrama %}\n\nTexto."))
        ->generate(assistantTurn($page));

    expect($reply->draft)->toContain('<figure><img src="/files/12"')
        ->and($reply->content)->toContain('só diagramas UML podem ser alterados');
});

it('says what a generated diagram still gets wrong', function () {
    // Two elements in one cell, twice: the repair does not fix it.
    $bad = componentsJson('VTEX', [1, 0]);
    assistantUml($bad, $bad);
    $page = assistantPage('Texto.');

    $reply = assistantReplying(draftReply('{% diagrama tipo="component" titulo="Pedidos" %}x{% enddiagrama %}'))
        ->generate(assistantTurn($page));

    expect($reply->draft)->toContain('{% uml type="component" title="Pedidos" %}')
        ->and($reply->content)->toContain('O diagrama "Pedidos" veio com pontos a corrigir');
});

it('teaches the assistant to ask for a diagram, never to write one', function () {
    $system = app(DocumentationChatPromptBuilder::class)->systemPrompt();

    expect($system)->toContain('{% diagrama tipo="sequence"')
        ->toContain('{% diagrama altera="[[BLOCK-3]]" %}')
        ->toContain(AssistantDiagramRequests::MAX_PER_TURN . ' pedidos por resposta')
        ->toContain('Não escreva `{% uml %}` nem o JSON')
        ->not->toContain('__MAX_DIAGRAMS__')
        ->not->toContain('"participants"');
});

it('never hands the diagram model a marker it could copy into a label', function () {
    $prompt = app(UmlDraftPromptBuilder::class)->userPrompt('sequence', 'P', 'Token {% secret %}[[SECRET-abc123]]{% endsecret %} e [[SECRET-zz9]]. Ver [[BLOCK-2]].', null, null, null);

    expect($prompt)->not->toContain('[[SECRET-')
        ->not->toContain('[[BLOCK-')
        ->toContain('[valor protegido]')
        ->toContain('[bloco]');
});
