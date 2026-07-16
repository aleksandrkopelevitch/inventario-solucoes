<?php

use App\Models\DocumentationAiGeneration;
use App\Models\DocumentationPage;
use App\Models\Solution;
use App\Models\User;
use App\Services\Documentation\DocumentationDraftPromptBuilder;
use App\Services\Documentation\DocumentationDraftService;
use Illuminate\Foundation\Testing\LazilyRefreshDatabase;
use Illuminate\Support\Facades\Storage;
use Laravel\Ai\Responses\AgentResponse;
use Laravel\Ai\Responses\Data\Meta;
use Laravel\Ai\Responses\Data\Usage;

uses(LazilyRefreshDatabase::class);

/** Dublê do serviço: captura o prompt/anexos e devolve um texto roteirizado em vez de chamar a API. */
function fakeDraftService(string $reply): DocumentationDraftService
{
    return new class($reply) extends DocumentationDraftService
    {
        public string $capturedPrompt = '';

        public array $capturedAttachments = [];

        public function __construct(private string $reply)
        {
            parent::__construct(app(DocumentationDraftPromptBuilder::class));
        }

        protected function prompt(string $prompt, array $attachments = []): AgentResponse
        {
            $this->capturedPrompt = $prompt;
            $this->capturedAttachments = $attachments;

            return new AgentResponse('fake', $this->reply, new Usage(10, 20), new Meta('anthropic', 'claude-sonnet-5'));
        }
    };
}

function generationFor(DocumentationPage $page, array $overrides = []): DocumentationAiGeneration
{
    return DocumentationAiGeneration::create(array_merge([
        'target_type'       => $page->getMorphClass(),
        'target_id'         => $page->getKey(),
        'solution_id'       => $page->container_id,
        'user_id'           => User::factory()->create()->id,
        'status'            => 'pending',
        'prompt'            => 'Documente o fluxo.',
        'context_media_ids' => [],
        'existing_content'  => null,
    ], $overrides));
}

it('creates documentation from scratch when the page is empty', function () {
    $solution = Solution::factory()->create();
    $page = DocumentationPage::factory()->for($solution, 'container')->create(['documentation' => null]);

    $service = fakeDraftService("## Objetivo\n\nTexto gerado.\n");
    $result = $service->generate(generationFor($page));

    expect($service->capturedPrompt)->toContain('A página está vazia')
        ->and($result->markdown)->toContain('## Objetivo')
        ->and($result->meta['model'])->toBe('claude-sonnet-5')
        // Campos de cache gravados (zerados sem prompt caching no pacote).
        ->and($result->meta['tokens'])->toHaveKeys(['prompt', 'completion', 'cache_write', 'cache_read']);
});

it('sends the full existing content to the model without truncating', function () {
    // A saída substitui a página inteira no editor, então o conteúdo atual não
    // pode ser cortado — o modelo precisa ver tudo para reescrever sem perder a
    // cauda. Um marcador no fim prova que nada foi descartado.
    $solution = Solution::factory()->create();
    $page = DocumentationPage::factory()->for($solution, 'container')->create();

    $existing = str_repeat('a', 500000) . 'MARCADOR_FINAL';

    $service = fakeDraftService('# ok');
    $service->generate(generationFor($page, ['existing_content' => $existing]));

    expect($service->capturedPrompt)
        ->toContain('MARCADOR_FINAL')
        ->not->toContain('[conteúdo atual truncado por tamanho]');
});

it('omits native attachments beyond the aggregate byte budget', function () {
    Storage::fake('public');
    config()->set('services.documentation_ai.max_attachment_bytes', 3000);
    $solution = Solution::factory()->create();
    $page = DocumentationPage::factory()->for($solution, 'container')->create();

    // PDFs reais (~2KB cada, magic bytes p/ o mime sniffar como application/pdf,
    // não text/*): o primeiro cabe (2009 <= 3000), o segundo estoura o teto.
    $pdf = "%PDF-1.4\n" . str_repeat("\x00", 2000);
    $first = $solution->addMediaFromString($pdf)->usingFileName('a.pdf')->toMediaCollection(Solution::CONTEXT_COLLECTION);
    $second = $solution->addMediaFromString($pdf)->usingFileName('b.pdf')->toMediaCollection(Solution::CONTEXT_COLLECTION);

    $service = fakeDraftService('# ok');
    $result = $service->generate(generationFor($page, ['context_media_ids' => [$first->id, $second->id]]));

    expect($service->capturedAttachments)->toHaveCount(1)
        ->and($result->meta['attached'])->toHaveCount(1)
        ->and($result->meta['omitted_attachments'])->toBe(['b.pdf']);
});

it('uses the existing content when the page already has documentation', function () {
    $solution = Solution::factory()->create();
    $page = DocumentationPage::factory()->for($solution, 'container')->create([
        'documentation' => '# Conteúdo antigo que deve ser reaproveitado',
    ]);

    $service = fakeDraftService('# Novo');
    $service->generate(generationFor($page, ['existing_content' => $page->documentation]));

    expect($service->capturedPrompt)
        ->toContain('CONTEÚDO ATUAL DA PÁGINA')
        ->toContain('Conteúdo antigo que deve ser reaproveitado');
});

it('inlines a selected text context document into the prompt', function () {
    Storage::fake('public');
    $solution = Solution::factory()->create();
    $page = DocumentationPage::factory()->for($solution, 'container')->create();

    $media = $solution
        ->addMediaFromString('SEGREDO: a ordem importa, BP antes do SVL')
        ->usingFileName('notas.txt')
        ->toMediaCollection(Solution::CONTEXT_COLLECTION);

    $service = fakeDraftService('# ok');
    $service->generate(generationFor($page, ['context_media_ids' => [$media->id]]));

    expect($service->capturedPrompt)
        ->toContain('notas.txt')
        ->toContain('SEGREDO: a ordem importa')
        ->and($service->capturedAttachments)->toBe([]); // texto entra no prompt, não como anexo
});

it('strips an accidental markdown code fence wrapping the whole reply', function () {
    $solution = Solution::factory()->create();
    $page = DocumentationPage::factory()->for($solution, 'container')->create();

    $result = fakeDraftService("```markdown\n# Título\n\nCorpo.\n```")->generate(generationFor($page));

    expect(trim($result->markdown))->toBe("# Título\n\nCorpo.");
});
