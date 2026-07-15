<?php

use App\Models\FlowspecChat;
use App\Models\FlowspecExample;
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

/** Dublê do serviço: devolve respostas roteirizadas em vez de chamar a API. */
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
        ->and($result->meta['tokens'])->toBe(['prompt' => 100, 'completion' => 200]);
});

it('re-prompts with the concrete errors until the answer validates', function () {
    $this->seed(FlowspecExampleSeeder::class);

    $valid = FlowspecExample::query()->where('slug', 'get-token-svl')->firstOrFail()->flow_spec;

    $broken = $valid;
    $rootKey = array_key_first($broken['flowSpec']);
    // choice apontando pra branch inexistente — o normalizador não corrige isso.
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

it('treats a conversational answer mentioning double-braces syntax as conversational, not broken JSON', function () {
    $this->seed(FlowspecExampleSeeder::class);

    $chat = chatWithUserMessage('como faço para referenciar o step anterior?');

    // Contém `{`/`}` (a sintaxe do domínio), mas não tem cerca de código nem
    // decodifica para um array com `meta`/`flowSpec` — não pode virar um erro
    // de "JSON inválido" que queima uma tentativa do loop de correção.
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
