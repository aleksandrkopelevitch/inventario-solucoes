<?php

use App\Enums\SubmissionSectionKey;
use App\Jobs\GenerateSubmissionChatReply;
use App\Models\CatiExample;
use App\Models\CatiGuideline;
use App\Models\Company;
use App\Models\Solution;
use App\Models\Submission;
use App\Models\SubmissionChat;
use App\Models\SubmissionMessage;
use App\Models\SubmissionSource;
use App\Models\User;
use App\Services\Cati\SubmissionChatPromptBuilder;
use App\Services\Cati\SubmissionChatService;
use App\Services\Cati\SubmissionContextResolver;
use Illuminate\Foundation\Testing\LazilyRefreshDatabase;
use Illuminate\Queue\Middleware\WithoutOverlapping;
use Illuminate\Support\Facades\Queue;
use Laravel\Ai\Responses\AgentResponse;
use Laravel\Ai\Responses\Data\Meta;
use Laravel\Ai\Responses\Data\Usage;

uses(LazilyRefreshDatabase::class);

/** Captures the prompt and returns scripted text instead of calling the API. */
function fakeCatiChat(string $reply): SubmissionChatService
{
    return new class($reply) extends SubmissionChatService
    {
        public string $capturedPrompt = '';

        public array $capturedAttachments = [];

        public function __construct(private string $reply)
        {
            parent::__construct(app(SubmissionContextResolver::class), app(SubmissionChatPromptBuilder::class));
        }

        protected function prompt(string $prompt, array $attachments = []): AgentResponse
        {
            $this->capturedPrompt = $prompt;
            $this->capturedAttachments = $attachments;

            return new AgentResponse('fake', $this->reply, new Usage(10, 20), new Meta('gemini', 'gemini-3.6-flash'));
        }
    };
}

function catiTurn(?Submission $submission = null, string $message = 'Roda numa VM na Google Cloud.'): SubmissionMessage
{
    $submission ??= Submission::factory()->withSections()->create();

    $chat = SubmissionChat::create([
        'user_id'       => User::factory()->create()->id,
        'submission_id' => $submission->id,
    ]);

    return $chat->messages()->create(['role' => 'user', 'content' => $message]);
}

it('splits one reply into conversation and per-section drafts', function () {
    $service = fakeCatiChat(<<<'REPLY'
    Anotei o resumo e os objetivos. Falta o custo de licenciamento — você tem esse número?

    ````rascunho:summary
    Ponto único de conexão entre a plataforma SaaS e a operação local.
    ````

    ````rascunho:objectives
    - Preservar a segmentação de rede.
    ````
    REPLY);

    $reply = $service->generate(catiTurn());

    expect($reply->drafts)->toHaveCount(2)
        ->and($reply->drafts[0])->toBe(['key' => 'summary', 'markdown' => 'Ponto único de conexão entre a plataforma SaaS e a operação local.'])
        ->and($reply->drafts[1]['key'])->toBe('objectives')
        // The conversation must not carry the draft text a second time.
        ->and($reply->content)->toBe('Anotei o resumo e os objetivos. Falta o custo de licenciamento — você tem esse número?')
        ->and($reply->content)->not->toContain('Ponto único');
});

it('treats a reply with no block as purely conversational', function () {
    $reply = fakeCatiChat('Qual o custo de licenciamento?')->generate(catiTurn());

    expect($reply->drafts)->toBe([])
        ->and($reply->content)->toBe('Qual o custo de licenciamento?');
});

it('drops a draft aimed at a section that does not exist, without leaking it', function () {
    $service = fakeCatiChat(<<<'REPLY'
    Preenchi o orçamento.

    ````rascunho:orcamento
    R$ 400 por mês.
    ````
    REPLY);

    $reply = $service->generate(catiTurn());

    // Writing it "somewhere approximate" would be worse than dropping it, and
    // the raw Markdown must not surface in the chat bubble either.
    expect($reply->drafts)->toBe([])
        ->and($reply->content)->toBe('Preenchi o orçamento.')
        ->and($reply->meta['rejected_drafts'])->toBe(['orcamento']);
});

it('keeps a three-backtick code block inside a draft intact', function () {
    $service = fakeCatiChat(<<<'REPLY'
    Segue.

    ````rascunho:architecture
    Instalação via APT:

    ```
    apt-get install skbridge
    ```
    ````
    REPLY);

    $reply = $service->generate(catiTurn());

    expect($reply->drafts[0]['markdown'])->toContain('```')
        ->and($reply->drafts[0]['markdown'])->toContain('apt-get install skbridge');
});

it('hands the model the catalog facts under a do-not-ask heading', function () {
    $solution = Solution::factory()->create(['name' => 'SkyMob', 'cloud' => 'gcp', 'criticality' => 'high']);
    $submission = Submission::factory()->withSections()->create(['solution_id' => $solution->id]);

    $service = fakeCatiChat('ok');
    $service->generate(catiTurn($submission));

    expect($service->capturedPrompt)->toContain('JÁ SABEMOS')
        ->toContain('não pergunte sobre isto')
        ->toContain('Nuvem: gcp')
        ->toContain('Solução no catálogo: SkyMob');
});

it('passes the committee questions in severity order', function () {
    $solution = Solution::factory()->create(['cloud' => 'aws']);
    $submission = Submission::factory()->withSections()->create(['solution_id' => $solution->id]);

    $service = fakeCatiChat('ok');
    $service->generate(catiTurn($submission));

    $prompt = $service->capturedPrompt;

    expect($prompt)->toContain('PERGUNTAS DO COMITÊ')
        ->and(mb_strpos($prompt, '[high]'))->toBeLessThan(mb_strpos($prompt, '[low]'));
});

it('inlines the gathered material and the active corpus', function () {
    $submission = Submission::factory()->withSections()->create();
    SubmissionSource::factory()->create([
        'submission_id'  => $submission->id,
        'label'          => 'CATI_antigo.pptx',
        'extracted_text' => '## Slide 1' . PHP_EOL . 'Propósito do SKBridge',
    ]);
    CatiGuideline::factory()->create(['title' => 'Nuvem alvo', 'content' => 'GCP é a nuvem alvo do M2C.']);
    CatiGuideline::factory()->inactive()->create(['title' => 'Diretriz revogada']);

    $service = fakeCatiChat('ok');
    $reply = $service->generate(catiTurn($submission));

    expect($service->capturedPrompt)->toContain('### Material: CATI_antigo.pptx')
        ->toContain('Propósito do SKBridge')
        ->toContain('GCP é a nuvem alvo')
        ->and($service->capturedPrompt)->not->toContain('Diretriz revogada')
        ->and($reply->meta['sources_inlined'])->toBe(['CATI_antigo.pptx']);
});

it('says when the examples are merely recent rather than related', function () {
    $solution = Solution::factory()->create(['category' => 'manufacturing']);
    $submission = Submission::factory()->withSections()->create(['solution_id' => $solution->id]);

    CatiExample::factory()->create(['name' => 'CATI Fiscal', 'tags' => ['tax']]);

    $service = fakeCatiChat('ok');
    $reply = $service->generate(catiTurn($submission));

    // A generic example is still worth more than none, but the prompt must not
    // imply it is relevant when it is only recent.
    expect($service->capturedPrompt)->toContain('o assunto pode não ter relação')
        ->and($reply->meta['examples_by_tag'])->toBeFalse();

    CatiExample::factory()->create(['name' => 'CATI Fábrica', 'tags' => ['manufacturing']]);

    $service = fakeCatiChat('ok');
    $reply = $service->generate(catiTurn($submission));

    expect($service->capturedPrompt)->toContain('parecidas com esta')
        ->and($reply->meta['examples_by_tag'])->toBeTrue();
});

it('shows the model what each section already says, so it revises instead of restarting', function () {
    $submission = Submission::factory()->withSections()->create();
    $submission->section(SubmissionSectionKey::Summary)->update(['content' => 'Texto já escrito.']);

    $service = fakeCatiChat('ok');
    $service->generate(catiTurn($submission->fresh()));

    expect($service->capturedPrompt)->toContain('ESTADO DAS SEÇÕES')
        ->toContain('Texto já escrito.')
        ->toContain('`architecture` — Arquitetura de solução [OBRIGATÓRIA]')
        ->toContain('Estado: VAZIA');
});

it('tells the model what each section has to answer, not just its label', function () {
    // SubmissionSectionKey::question() is the module's own definition of a
    // section. It used to reach only the opening message, so the interview
    // itself saw "domains_data — Domínios e dados" and guessed the rest.
    $service = fakeCatiChat('ok');
    $service->generate(catiTurn());

    expect($service->capturedPrompt)
        ->toContain('Pergunta: ' . SubmissionSectionKey::DomainsData->question())
        ->toContain('Pergunta: ' . SubmissionSectionKey::PlanCosts->question())
        // "has text" and "is answered" are different states, and the question
        // is what lets the model tell them apart.
        ->toContain('Texto escrito não é o mesmo');
});

it('marks which sections only the deck asks for, so they never outrank a mandatory one', function () {
    $service = fakeCatiChat('ok');
    $service->generate(catiTurn());

    expect($service->capturedPrompt)
        ->toContain('`current_state` — Cenário atual [só no deck]')
        ->toContain('`summary` — Resumo da proposta [OBRIGATÓRIA]');
});

it('hands over the sections each catalog fact informs, not just the fact', function () {
    // SubmissionRequirements attaches that mapping deliberately — the vendor
    // belongs in the summary, the operating model AND the costs, and a fact
    // rendered as bare `label: value` makes the model rediscover it every turn.
    $vendor = Company::factory()->create(['name' => 'Acme']);
    $solution = Solution::factory()->create(['name' => 'SkyMob', 'vendor_company_id' => $vendor->id]);

    $service = fakeCatiChat('ok');
    $service->generate(catiTurn(Submission::factory()->withSections()->create(['solution_id' => $solution->id])));

    expect($service->capturedPrompt)
        ->toContain('- Fornecedor: Acme → informa: summary, operating_model, plan_costs');
});

it('warns the model about a credential in the material instead of just inlining it', function () {
    // The scanner flags it and the UI badges it; the prompt inlined the raw
    // text with no warning at all. A draft that quotes the secret gets
    // promoted into the Solution's documentation and printed on a slide.
    $submission = Submission::factory()->withSections()->create();
    SubmissionSource::factory()->create([
        'submission_id'      => $submission->id,
        'label'              => 'config.txt',
        'extracted_text'     => 'client_secret=SuperSecreta123',
        'sensitive_findings' => [['type' => 'Credencial atribuída a um campo', 'sample' => 'client_secre…']],
    ]);

    $service = fakeCatiChat('ok');
    $service->generate(catiTurn($submission));

    expect($service->capturedPrompt)
        ->toContain('CONTÉM POSSÍVEL CREDENCIAL (Credencial atribuída a um campo)')
        ->toContain('não copie o valor para nenhum rascunho');
});

it('tells the model when material was left out, so it does not answer as if it saw everything', function () {
    config()->set('services.cati.doc_budget_chars', 40);

    $submission = Submission::factory()->withSections()->create();
    SubmissionSource::factory()->create(['submission_id' => $submission->id, 'label' => 'primeiro.md', 'extracted_text' => str_repeat('a', 60)]);
    SubmissionSource::factory()->create(['submission_id' => $submission->id, 'label' => 'segundo.md', 'extracted_text' => 'texto curto']);

    $service = fakeCatiChat('ok');
    $service->generate(catiTurn($submission));

    expect($service->capturedPrompt)
        ->toContain('MATERIAL NÃO INCLUÍDO NESTE TURNO')
        ->toContain('segundo.md');
});

it('drops the oldest material once the character budget runs out, and records it', function () {
    config()->set('services.cati.doc_budget_chars', 40);

    $submission = Submission::factory()->withSections()->create();
    SubmissionSource::factory()->create(['submission_id' => $submission->id, 'label' => 'primeiro.md', 'extracted_text' => str_repeat('a', 60)]);
    SubmissionSource::factory()->create(['submission_id' => $submission->id, 'label' => 'segundo.md', 'extracted_text' => 'texto curto']);

    $reply = fakeCatiChat('ok')->generate(catiTurn($submission));

    // The user attached it on purpose — it can't vanish without a record.
    expect($reply->meta['sources_inlined'])->toBe(['primeiro.md'])
        ->and($reply->meta['sources_omitted'])->toBe(['segundo.md']);
});

it('trims the oldest turns to fit the history budget, and says it did', function () {
    // Material, examples and guidelines are all bounded already; the history
    // was not, and it is re-sent in full on EVERY turn. A long interview is the
    // normal case here, so the failure mode was a provider error arriving
    // halfway through a submission somebody had spent the afternoon on.
    config()->set('services.cati.history_budget_chars', 120);

    $message = catiTurn();
    $chat = $message->chat;
    $message->delete();

    foreach (range(1, 6) as $i) {
        $chat->messages()->create(['role' => 'user', 'content' => "Pergunta {$i} " . str_repeat('x', 50)]);
    }
    $latest = $chat->messages()->create(['role' => 'user', 'content' => 'Pergunta final.']);

    $service = fakeCatiChat('ok');
    $service->generate($latest);

    expect($service->capturedPrompt)
        // Oldest-first: the recent turns are the ones the next answer has to
        // be consistent with.
        ->not->toContain('Pergunta 1 ')
        ->toContain('Pergunta 6 ')
        // Stated, never hidden — a conversation that quietly forgot its own
        // beginning reads as the assistant losing track.
        ->toContain('mais antiga(s) foram omitidas por limite de contexto');
});

it('keeps the newest turn even when it alone blows the history budget', function () {
    // Answering with no history at all is worse than being slightly over.
    config()->set('services.cati.history_budget_chars', 10);

    $message = catiTurn();
    $chat = $message->chat;
    $message->delete();

    $chat->messages()->create(['role' => 'assistant', 'content' => 'Resposta anterior ' . str_repeat('y', 200)]);
    $latest = $chat->messages()->create(['role' => 'user', 'content' => 'Pergunta atual.']);

    $service = fakeCatiChat('ok');
    $service->generate($latest);

    expect($service->capturedPrompt)->toContain('Resposta anterior');
});

it('carries the conversation history into the next turn', function () {
    $message = catiTurn();
    $chat = $message->chat;
    $message->delete();

    $chat->messages()->create(['role' => 'user', 'content' => 'Primeira pergunta.']);
    $chat->messages()->create(['role' => 'assistant', 'content' => 'Primeira resposta.']);
    $latest = $chat->messages()->create(['role' => 'user', 'content' => 'Segunda pergunta.']);

    $service = fakeCatiChat('ok');
    $service->generate($latest);

    expect($service->capturedPrompt)->toContain('CONVERSA ATÉ AQUI')
        ->toContain('Arquiteto: Primeira pergunta.')
        ->toContain('Você: Primeira resposta.')
        // The latest message is the question being answered, not history.
        ->and(mb_substr_count($service->capturedPrompt, 'Segunda pergunta.'))->toBe(1);
});

it('persists the reply and its drafts through the job', function () {
    Queue::fake();

    $message = catiTurn();

    app()->instance(SubmissionChatService::class, fakeCatiChat("Feito.\n\n````rascunho:summary\nResumo proposto.\n````"));

    (new GenerateSubmissionChatReply($message))->handle(app(SubmissionChatService::class));

    $assistant = $message->chat->messages()->where('role', 'assistant')->first();

    expect($assistant->content)->toBe('Feito.')
        ->and($assistant->drafts)->toBe([['key' => 'summary', 'markdown' => 'Resumo proposto.']])
        ->and($assistant->meta['model'])->toBe(config('services.cati.model'));
});

it('produces no reply for a turn that was superseded', function () {
    $message = catiTurn();
    // The user gave up waiting and sent again — the stall guard had reopened
    // the composer, and the queue then resurrected this job.
    $message->chat->messages()->create(['role' => 'user', 'content' => 'Deixa pra lá.']);

    (new GenerateSubmissionChatReply($message))->handle(fakeCatiChat('resposta atrasada'));

    expect($message->chat->messages()->where('role', 'assistant')->count())->toBe(0);
});

it('answers with a failure message so the chat never stays pending', function () {
    $message = catiTurn();

    (new GenerateSubmissionChatReply($message))->failed(new RuntimeException('API key inválida: sk-abc123'));

    $assistant = $message->chat->messages()->where('role', 'assistant')->first();

    expect($assistant->content)->toContain('Não consegui gerar uma resposta')
        ->and($assistant->meta['status'])->toBe('failed')
        ->and($assistant->meta['error_type'])->toBe(RuntimeException::class)
        // The provider's message can embed URLs, headers or key fragments, and
        // meta is an audit trail that could be exported.
        ->and(json_encode($assistant->meta))->not->toContain('sk-abc123');
});

it('serializes generation per chat', function () {
    $message = catiTurn();
    $middleware = (new GenerateSubmissionChatReply($message))->middleware();

    expect($middleware[0])->toBeInstanceOf(WithoutOverlapping::class);
});
