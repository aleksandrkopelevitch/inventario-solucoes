<?php

use App\Models\DocumentationPage;
use App\Models\FlowspecChat;
use App\Models\FlowspecExample;
use App\Models\FlowspecGuideline;
use App\Models\FlowspecMessage;
use App\Models\Solution;
use App\Services\Flowspec\CredentialScrubber;
use App\Services\Flowspec\DigibeeFlowspecNormalizer;
use App\Services\Flowspec\DigibeeFlowspecValidator;
use App\Services\Flowspec\FlowspecContextBudget;
use App\Services\Flowspec\FlowspecContextResolver;
use App\Services\Flowspec\FlowspecGenerationService;
use App\Services\Flowspec\FlowspecPromptBuilder;
use Database\Seeders\FlowspecExampleSeeder;
use Illuminate\Foundation\Testing\LazilyRefreshDatabase;
use Laravel\Ai\Responses\AgentResponse;
use Laravel\Ai\Responses\Data\Meta;
use Laravel\Ai\Responses\Data\Usage;

uses(LazilyRefreshDatabase::class);

/** Test double for the service: returns scripted responses instead of calling the API. */
function fakeGenerationService(array $scriptedTexts): FlowspecGenerationService
{
    return new class($scriptedTexts) extends FlowspecGenerationService
    {
        public function __construct(private array $scripted)
        {
            parent::__construct(
                app(FlowspecContextResolver::class),
                app(FlowspecPromptBuilder::class),
                app(DigibeeFlowspecNormalizer::class),
                app(DigibeeFlowspecValidator::class),
                app(CredentialScrubber::class),
                app(FlowspecContextBudget::class),
            );
        }

        /** @var list<object> the attachments the last call was made with */
        public array $lastAttachments = [];

        protected function prompt(string $prompt, array $attachments = []): AgentResponse
        {
            $this->lastAttachments = $attachments;

            return new AgentResponse('fake', array_shift($this->scripted), new Usage(100, 200), new Meta('anthropic', 'claude-sonnet-5'));
        }
    };
}

function chatWithUserMessage(string $content): FlowspecChat
{
    $chat = FlowspecChat::factory()->create();
    $chat->messages()->create(['role' => 'user', 'content' => $content]);

    return $chat;
}

it('returns a validated document on the first valid answer', function () {
    $this->seed(FlowspecExampleSeeder::class);

    $valid = FlowspecExample::query()->where('slug', 'get-token-svl')->firstOrFail()->flow_spec;
    $chat = chatWithUserMessage('gera o flowspec de token');

    $result = fakeGenerationService([json_encode($valid)])->generate($chat->messages()->firstOrFail());

    expect($result->validated)->toBeTrue()
        ->and($result->document)->toHaveKeys(['meta', 'flowSpec'])
        ->and($result->meta['attempts'])->toHaveCount(1)
        ->and($result->meta['tokens'])->toBe(['prompt' => 100, 'completion' => 200, 'cache_write' => 0, 'cache_read' => 0]);
});

it('re-prompts with the concrete errors until the answer validates', function () {
    $this->seed(FlowspecExampleSeeder::class);

    $valid = FlowspecExample::query()->where('slug', 'get-token-svl')->firstOrFail()->flow_spec;

    $broken = $valid;
    $rootKey = array_key_first($broken['flowSpec']);
    // choice pointing to a nonexistent branch — the normalizer doesn't fix this.
    $broken['flowSpec'][$rootKey][1]['when'][0]['target'] = 'branch-que-nao-existe';

    $chat = chatWithUserMessage('gera o flowspec de token');

    $result = fakeGenerationService([json_encode($broken), json_encode($valid)])
        ->generate($chat->messages()->firstOrFail());

    expect($result->validated)->toBeTrue()
        ->and($result->meta['attempts'])->toHaveCount(2)
        ->and($result->meta['attempts'][0]['errors'])->not->toBe([]);
});

it('withholds the document (and raw text) when the best attempt still leaks a literal credential', function () {
    $this->seed(FlowspecExampleSeeder::class);
    config()->set('services.flowspec.max_attempts', 1);

    $leaky = FlowspecExample::query()->where('slug', 'get-token-svl')->firstOrFail()->flow_spec;
    $rootKey = array_key_first($leaky['flowSpec']);
    // A literal secret CredentialScrubber flags, so validation never passes.
    $leaky['flowSpec'][$rootKey][0]['params']['json'] = '{"x-api-key": "chave-literal-123"}';

    $chat = chatWithUserMessage('gera o flowspec de token');

    $result = fakeGenerationService([json_encode($leaky)])->generate($chat->messages()->firstOrFail());

    expect($result->validated)->toBeFalse()
        ->and($result->credentialLeak)->toBeTrue()
        ->and($result->document)->toBeNull(); // never persisted or rendered
});

it('does not fabricate a fix for a duplicated step UUID, leaving it for the validator', function () {
    $uuid = '11111111-1111-4111-8111-111111111111';
    $document = [
        'meta'     => [$uuid => ['position' => ['x' => 0, 'y' => 0]]],
        'flowSpec' => [
            "disconnected-root:{$uuid}" => [
                ['id' => $uuid, 'type' => 'jslt'],
                ['id' => $uuid, 'type' => 'jslt'],
            ],
        ],
    ];

    $result = app(DigibeeFlowspecNormalizer::class)->normalize($document);

    $steps = $result->document['flowSpec']["disconnected-root:{$uuid}"];

    // Both keep the (still duplicated) id — the normalizer no longer maps both
    // to one new UUID and no longer logs a misleading "regenerado" fix.
    expect($steps[0]['id'])->toBe($uuid)
        ->and($steps[1]['id'])->toBe($uuid)
        ->and(collect($result->fixes)->contains(fn (string $f) => str_contains($f, "regenerado: {$uuid}")))->toBeFalse();
});

it('gives up after max_attempts keeping the best attempt', function () {
    $this->seed(FlowspecExampleSeeder::class);
    config()->set('services.flowspec.max_attempts', 2);

    $broken = FlowspecExample::query()->where('slug', 'get-token-svl')->firstOrFail()->flow_spec;
    $rootKey = array_key_first($broken['flowSpec']);
    $broken['flowSpec'][$rootKey][1]['when'][0]['target'] = 'branch-que-nao-existe';

    $chat = chatWithUserMessage('gera o flowspec de token');

    $result = fakeGenerationService([json_encode($broken), json_encode($broken)])
        ->generate($chat->messages()->firstOrFail());

    expect($result->validated)->toBeFalse()
        ->and($result->document)->not->toBeNull()
        ->and($result->meta['attempts'])->toHaveCount(2);
});

it('treats an answer without JSON as conversational, without re-prompting', function () {
    $this->seed(FlowspecExampleSeeder::class);

    $chat = chatWithUserMessage('qual endpoint devo usar?');

    $result = fakeGenerationService(['Preciso saber qual sistema recebe o POST — SVL ou IAM?'])
        ->generate($chat->messages()->firstOrFail());

    expect($result->document)->toBeNull()
        ->and($result->validated)->toBeFalse()
        ->and($result->text)->toContain('SVL ou IAM')
        ->and($result->meta['attempts'])->toHaveCount(1);
});

it('suggests documentation for a solution the conversational answer names', function () {
    $this->seed(FlowspecExampleSeeder::class);

    $iam = Solution::factory()->create(['name' => 'IAM']);
    $page = DocumentationPage::factory()->for($iam, 'container')->create(['title' => 'Autenticação', 'documentation' => 'x']);

    $chat = chatWithUserMessage('gera um flowspec de token');

    $result = fakeGenerationService(['Preciso saber como o IAM autentica antes de continuar — descreva ou anexe a documentação dele.'])
        ->generate($chat->messages()->firstOrFail());

    expect($result->meta['suggested_documents'])->toBe([
        ['type' => 'page', 'id' => $page->id, 'label' => 'IAM — Autenticação'],
    ]);
});

it('does not suggest documents when the loop exhausts attempts on broken JSON', function () {
    $this->seed(FlowspecExampleSeeder::class);

    $iam = Solution::factory()->create(['name' => 'IAM']);
    DocumentationPage::factory()->for($iam, 'container')->create(['title' => 'Autenticação', 'documentation' => 'x']);

    // Fenced but invalid JSON across all 3 attempts (max_attempts): $document
    // ends up null WITHOUT being conversational — the text mentions IAM, but
    // the guard doesn't infer suggestions from broken JSON.
    $broken = "Preciso do IAM.\n```json\n{ \"meta\": }\n```";
    $chat = chatWithUserMessage('gera um flowspec de token');

    $result = fakeGenerationService([$broken, $broken, $broken])->generate($chat->messages()->firstOrFail());

    expect($result->document)->toBeNull()
        ->and($result->meta['suggested_documents'])->toBe([]);
});

it('does not suggest documents for a validated flowSpec answer', function () {
    $this->seed(FlowspecExampleSeeder::class);

    $valid = FlowspecExample::query()->where('slug', 'get-token-svl')->firstOrFail()->flow_spec;
    $chat = chatWithUserMessage('gera o flowspec de token');

    $result = fakeGenerationService([json_encode($valid)])->generate($chat->messages()->firstOrFail());

    expect($result->meta['suggested_documents'])->toBe([]);
});

it('treats a conversational answer mentioning double-braces syntax as conversational, not broken JSON', function () {
    $this->seed(FlowspecExampleSeeder::class);

    $chat = chatWithUserMessage('como faço para referenciar o step anterior?');

    // Contains `{`/`}` (the domain syntax), but has no code fence and doesn't
    // decode to an array with `meta`/`flowSpec` — must not turn into an
    // "invalid JSON" error that burns an attempt of the correction loop.
    $answer = 'Use a sintaxe {{ step.jslt-1.token }} para referenciar o resultado do step anterior.';

    $result = fakeGenerationService([$answer])->generate($chat->messages()->firstOrFail());

    expect($result->document)->toBeNull()
        ->and($result->validated)->toBeFalse()
        ->and($result->text)->toBe($answer)
        ->and($result->meta['attempts'])->toHaveCount(1)
        ->and($result->meta['attempts'][0]['errors'])->toBe([]);
});

it('still treats a fenced but malformed flowSpec JSON block as a real correction-worthy error', function () {
    $this->seed(FlowspecExampleSeeder::class);

    $chat = chatWithUserMessage('gera o flowspec de token');
    $valid = FlowspecExample::query()->where('slug', 'get-token-svl')->firstOrFail()->flow_spec;

    // Fenced JSON that clearly INTENDS a flowSpec (mentions "meta"/"flowSpec")
    // but is malformed — a broken generation attempt to correct.
    $broken = "Aqui está:\n```json\n{ \"meta\": {}, \"flowSpec\": }\n```";

    $result = fakeGenerationService([$broken, json_encode($valid)])
        ->generate($chat->messages()->firstOrFail());

    expect($result->validated)->toBeTrue()
        ->and($result->meta['attempts'])->toHaveCount(2)
        ->and($result->meta['attempts'][0]['errors'][0])->toContain('não é um JSON parseável');
});

it('treats a conversational answer containing a non-flowSpec JSON snippet as conversational', function () {
    $this->seed(FlowspecExampleSeeder::class);

    $chat = chatWithUserMessage('o que esse trecho de params faz?');

    // A fenced JSON FRAGMENT cited to illustrate a text answer — it decodes
    // fine but has no `meta`/`flowSpec` key, so it must NOT be treated as a
    // flowSpec attempt (no validation, no correction, no document).
    $answer = "Esse bloco define o corpo da requisição:\n```json\n{ \"failOnError\": false, \"timeout\": 30 }\n```\nEle não altera o roteamento.";

    $result = fakeGenerationService([$answer])->generate($chat->messages()->firstOrFail());

    expect($result->document)->toBeNull()
        ->and($result->validated)->toBeFalse()
        ->and($result->text)->toBe($answer)
        ->and($result->meta['attempts'])->toHaveCount(1)
        ->and($result->meta['attempts'][0]['errors'])->toBe([]);
});

it('always folds every active guideline into the system prompt and omits inactive ones', function () {
    FlowspecGuideline::factory()->create(['title' => 'Boas práticas Digibee', 'content' => 'Prefira sempre reaproveitar conectores existentes.']);
    FlowspecGuideline::factory()->inactive()->create(['title' => 'Rascunho antigo', 'content' => 'Texto de um rascunho desativado.']);

    $prompt = app(FlowspecPromptBuilder::class)->systemPrompt();

    expect($prompt)->toContain('Boas práticas Digibee')
        ->toContain('Prefira sempre reaproveitar conectores existentes.')
        ->not->toContain('Rascunho antigo')
        ->not->toContain('Texto de um rascunho desativado.');
});

it('omits the guidelines section entirely when there are none active', function () {
    $prompt = app(FlowspecPromptBuilder::class)->systemPrompt();

    expect($prompt)->not->toContain('Diretrizes adicionais definidas pela equipe');
});

it('collapses every exhausted-attempt raw JSON dump in history, keeping only the latest real flowSpec in full', function () {
    $chat = FlowspecChat::factory()->create();

    $brokenAttempt = FlowspecMessage::factory()->assistant()->create([
        'flowspec_chat_id' => $chat->id,
        'content'          => '{"meta": {"broken": true}, "flowSpec": {"disconnected-root:x": [',
        'flow_spec'        => null,
    ]);
    $olderValid = FlowspecMessage::factory()->assistant()->create([
        'flowspec_chat_id' => $chat->id,
        'content'          => 'flowSpec gerado e validado — pronto para colar no canvas da Digibee.',
        'flow_spec'        => ['meta' => [], 'flowSpec' => ['disconnected-root:old' => []]],
    ]);
    $latestValid = FlowspecMessage::factory()->assistant()->create([
        'flowspec_chat_id' => $chat->id,
        'content'          => 'flowSpec gerado e validado — pronto para colar no canvas da Digibee.',
        'flow_spec'        => ['meta' => [], 'flowSpec' => ['disconnected-root:new' => []]],
    ]);

    $history = collect([$brokenAttempt, $olderValid, $latestValid]);
    $context = emptyFlowspecContext();

    $prompt = app(FlowspecPromptBuilder::class)->userPrompt($context, 'próximo pedido', $history)->text;

    expect($prompt)->not->toContain('"broken": true')
        ->toContain('omitida do histórico')
        ->toContain('disconnected-root:new')
        ->not->toContain('disconnected-root:old')
        ->toContain('superado pelas seguintes');
});

it('does not collapse a normal conversational reply in history, even one starting with a brace-like character', function () {
    $chat = FlowspecChat::factory()->create();

    $conversational = FlowspecMessage::factory()->assistant()->create([
        'flowspec_chat_id' => $chat->id,
        'content'          => 'Prefiro confirmar antes de gerar: qual sistema deve receber a resposta?',
        'flow_spec'        => null,
    ]);

    $history = collect([$conversational]);
    $context = emptyFlowspecContext();

    $prompt = app(FlowspecPromptBuilder::class)->userPrompt($context, 'próximo pedido', $history)->text;

    expect($prompt)->toContain('Prefiro confirmar antes de gerar');
});
