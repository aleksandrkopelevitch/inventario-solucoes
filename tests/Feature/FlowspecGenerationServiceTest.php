<?php

use App\Models\DocumentationPage;
use App\Models\FlowspecChat;
use App\Models\FlowspecExample;
use App\Models\Solution;
use App\Services\Flowspec\DigibeeFlowspecNormalizer;
use App\Services\Flowspec\DigibeeFlowspecValidator;
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
            );
        }

        protected function prompt(string $prompt): AgentResponse
        {
            return new AgentResponse('fake', array_shift($this->scripted), new Usage(100, 200), new Meta('anthropic', 'claude-sonnet-5'));
        }
    };
}

function chatWithUserMessage(string $content): FlowspecChat
{
    $chat = FlowspecChat::factory()->create();
    $chat->messages()->create(['role' => 'user', 'content' => $content, 'meta' => ['solution_ids' => []]]);

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

it('suggests documentation for a solution the conversational answer names but was not yet considered', function () {
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

it('still treats a fenced but malformed JSON block as a real correction-worthy error', function () {
    $this->seed(FlowspecExampleSeeder::class);

    $chat = chatWithUserMessage('gera o flowspec de token');
    $valid = FlowspecExample::query()->where('slug', 'get-token-svl')->firstOrFail()->flow_spec;

    $broken = "Aqui está:\n```json\n{ this is not valid json }\n```";

    $result = fakeGenerationService([$broken, json_encode($valid)])
        ->generate($chat->messages()->firstOrFail());

    expect($result->validated)->toBeTrue()
        ->and($result->meta['attempts'])->toHaveCount(2)
        ->and($result->meta['attempts'][0]['errors'][0])->toContain('não é um JSON parseável');
});
