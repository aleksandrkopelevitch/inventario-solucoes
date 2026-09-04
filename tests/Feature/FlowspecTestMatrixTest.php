<?php

use App\Actions\Flowspec\BuildPipelineTestMatrix;
use App\Exceptions\DigibeeApiException;
use App\Support\Digibee\Testing\Assertion;
use App\Support\Digibee\Testing\AssertionOperator;
use App\Support\Digibee\Testing\JsonPath;
use App\Support\Digibee\Testing\PipelineTestCase;
use App\Support\Digibee\Testing\PipelineTestSuite;
use App\Support\Digibee\Testing\StatusExpectation;
use App\Support\Digibee\Testing\TestCaseCategory;
use Illuminate\Support\Str;

/**
 * A `{meta, flowSpec}` document in the shape the generator emits — rooted at
 * `disconnected-root:<uuid>`, because that is the paste format.
 *
 * @param  list<array<string, mixed>>  $entrySteps
 * @param  array<string, list<array<string, mixed>>>  $otherBranches
 */
function flowspecDocument(array $entrySteps, array $otherBranches = []): array
{
    $root = 'disconnected-root:' . Str::uuid();

    return [
        'meta'     => [],
        'flowSpec' => [$root => $entrySteps, ...$otherBranches],
    ];
}

function matrixFor(array $document, string $name = 'sch-rastreio-pedidos'): PipelineTestSuite
{
    return app(BuildPipelineTestMatrix::class)->handle($document, $name);
}

function caseNamed(PipelineTestSuite $suite, string $needle): ?PipelineTestCase
{
    foreach ($suite->cases as $case) {
        if (str_contains($case->name, $needle)) {
            return $case;
        }
    }

    return null;
}

/*
|--------------------------------------------------------------------------
| JsonPath — the subset, and what it refuses
|--------------------------------------------------------------------------
*/

it('resolves the supported path shapes as a nodelist', function () {
    $body = ['sucesso' => false, 'itens' => [['sku' => 'A'], ['sku' => 'B']]];

    expect(JsonPath::find($body, '$'))->toBe([$body])
        ->and(JsonPath::find($body, '$.sucesso'))->toBe([false])
        ->and(JsonPath::find($body, 'sucesso'))->toBe([false])
        ->and(JsonPath::find($body, "\$['sucesso']"))->toBe([false])
        ->and(JsonPath::find($body, '$.itens[1].sku'))->toBe(['B'])
        ->and(JsonPath::find($body, '$.itens[*].sku'))->toBe(['A', 'B'])
        ->and(JsonPath::find($body, '$.nada'))->toBe([])
        ->and(JsonPath::find($body, '$.itens[9]'))->toBe([]);
});

it('refuses a choice filter expression instead of silently matching nothing', function () {
    // This exact syntax is what a flowSpec `choice` routes on, so it WILL be
    // pasted into an assertion. Treated as "matched nothing" it would turn an
    // `exists` into a silent false and a `missing` into a silent pass.
    expect(JsonPath::supports('$.[?(@.status >= 200 && @.status <= 299)]'))->toBeFalse()
        ->and(JsonPath::supports('$..sku'))->toBeFalse()
        ->and(JsonPath::supports('$.itens[0:2]'))->toBeFalse()
        ->and(JsonPath::supports('$.itens[*].sku'))->toBeTrue();

    expect(fn () => JsonPath::find([], '$.[?(@.x == 1)]'))
        ->toThrow(InvalidArgumentException::class, 'Unsupported JsonPath syntax');
});

/*
|--------------------------------------------------------------------------
| Status expectations
|--------------------------------------------------------------------------
*/

it('matches an exact status, a family and an alternation', function () {
    expect(StatusExpectation::parse(200)->matches(200))->toBeTrue()
        ->and(StatusExpectation::parse(200)->matches(201))->toBeFalse()
        ->and(StatusExpectation::parse('2xx')->matches(204))->toBeTrue()
        ->and(StatusExpectation::parse('2xx')->matches(404))->toBeFalse()
        ->and(StatusExpectation::parse('2xx|404')->matches(404))->toBeTrue();
});

it('treats !5xx as a complete expectation, not an empty one', function () {
    $handled = StatusExpectation::handled();

    // The resilience assertion: a malformed body may legitimately answer 400,
    // 422 or 200-with-an-error-object. An unhandled 500 is the defect.
    expect($handled->matches(200))->toBeTrue()
        ->and($handled->matches(400))->toBeTrue()
        ->and($handled->matches(422))->toBeTrue()
        ->and($handled->matches(500))->toBeFalse()
        ->and($handled->matches(503))->toBeFalse()
        ->and($handled->describe())->toBe('qualquer status que não seja 5xx');
});

it('refuses a status expectation it cannot honour', function () {
    expect(fn () => StatusExpectation::parse('quase-200'))
        ->toThrow(InvalidArgumentException::class, 'Unsupported status expectation');
});

/*
|--------------------------------------------------------------------------
| Assertions
|--------------------------------------------------------------------------
*/

it('compares strictly, so false, 0, empty string and null stay four answers', function () {
    $equalsFalse = new Assertion('$.sucesso', AssertionOperator::Equals, false);

    expect($equalsFalse->evaluate(['sucesso' => false])->passed)->toBeTrue()
        ->and($equalsFalse->evaluate(['sucesso' => 0])->passed)->toBeFalse()
        ->and($equalsFalse->evaluate(['sucesso' => ''])->passed)->toBeFalse()
        ->and($equalsFalse->evaluate(['sucesso' => null])->passed)->toBeFalse();
});

it('reads contains as substring for a string and membership for a list', function () {
    $contains = new Assertion('$.mensagem', AssertionOperator::Contains, 'não localizado');

    expect($contains->evaluate(['mensagem' => 'Cliente não localizado na base'])->passed)->toBeTrue()
        ->and($contains->evaluate(['mensagem' => 'Cliente encontrado'])->passed)->toBeFalse();

    $inList = new Assertion('$.tags', AssertionOperator::Contains, 'urgente');

    expect($inList->evaluate(['tags' => ['normal', 'urgente']])->passed)->toBeTrue()
        ->and($inList->evaluate(['tags' => ['normal']])->passed)->toBeFalse();
});

it('fails every operator but missing when the path matched nothing', function () {
    $body = ['outro' => 1];

    foreach ([AssertionOperator::Equals, AssertionOperator::NotEquals, AssertionOperator::Contains, AssertionOperator::Exists] as $operator) {
        // `notEquals` included, deliberately: "the field differs from x" is not
        // something an absent field satisfies.
        expect((new Assertion('$.sucesso', $operator, 'x'))->evaluate($body)->passed)
            ->toBeFalse("{$operator->value} should not pass on an absent path");
    }

    expect((new Assertion('$.sucesso', AssertionOperator::Missing))->evaluate($body)->passed)->toBeTrue();
});

it('passes a wildcard assertion when any matched node satisfies it', function () {
    $assertion = new Assertion('$.itens[*].status', AssertionOperator::Equals, 'ERRO');

    expect($assertion->evaluate(['itens' => [['status' => 'OK'], ['status' => 'ERRO']]])->passed)->toBeTrue()
        ->and($assertion->evaluate(['itens' => [['status' => 'OK']]])->passed)->toBeFalse();
});

it('explains a failure with both what was expected and what came back', function () {
    $outcome = (new Assertion('$.sucesso', AssertionOperator::Equals, false))->evaluate(['sucesso' => true]);

    // The self-healing loop is only as good as this string.
    expect($outcome->reason)->toBe('`$.sucesso` deve ser igual a `false`, veio `true`.')
        ->and($outcome->actual)->toBe([true]);

    $absent = (new Assertion('$.sucesso', AssertionOperator::Equals, false))->evaluate([]);

    expect($absent->reason)->toBe('`$.sucesso` não existe no corpo da resposta.');
});

it('collects a re-promptable error for each thing wrong with an authored assertion', function () {
    $errors = [];

    Assertion::fromArray(['jsonPath' => '$.a', 'operator' => 'quaseIgual'], $errors);
    Assertion::fromArray(['jsonPath' => '$.[?(@.x == 1)]', 'operator' => 'equals', 'value' => 1], $errors);
    Assertion::fromArray(['jsonPath' => '$.a', 'operator' => 'equals'], $errors);
    Assertion::fromArray(['operator' => 'exists'], $errors);

    expect($errors)->toHaveCount(4)
        ->and($errors[0])->toContain('`operator` desconhecido')
        ->and($errors[1])->toContain('fora do subconjunto suportado')
        ->and($errors[2])->toContain('exige `value`')
        ->and($errors[3])->toContain('sem `jsonPath`');

    // `exists` needs no value and must not be refused for lacking one.
    $ok = [];
    expect(Assertion::fromArray(['jsonPath' => '$.a', 'operator' => 'exists'], $ok))->not->toBeNull()
        ->and($ok)->toBe([]);
});

/*
|--------------------------------------------------------------------------
| The suite document
|--------------------------------------------------------------------------
*/

it('round-trips a suite through its JSON shape, raw body and blocked reason included', function () {
    $suite = new PipelineTestSuite('order-tracking', 'sch-rastreio-pedidos', 'test', [
        new PipelineTestCase(
            name: 'Corpo malformado',
            category: TestCaseCategory::Contract,
            expects: StatusExpectation::handled(),
            body: '{"cpf": ',
        ),
        new PipelineTestCase(
            name: 'Roteia para «erro»',
            category: TestCaseCategory::BranchCoverage,
            expects: StatusExpectation::ok(),
            assertions: [new Assertion('$.sucesso', AssertionOperator::Equals, false)],
            covers: 'erro',
            blocked: 'exige um mock',
        ),
    ]);

    $errors = [];
    $reloaded = PipelineTestSuite::fromArray($suite->toArray(), $errors);

    expect($errors)->toBe([])
        ->and($reloaded->toArray())->toBe($suite->toArray())
        ->and($reloaded->cases[0]->body)->toBe('{"cpf": ')
        ->and($reloaded->cases[1]->blocked)->toBe('exige um mock')
        ->and($reloaded->cases[1]->runnable())->toBeFalse();
});

it('puts the environment in the host and the major version in the path', function () {
    // Digibee's REST trigger reference:
    // https://{test|api}.godigibee.io/pipeline/{realm}/v{n}/{pipeline-name}
    // — NOT one host with the environment as a path segment, which is what the
    // APLA spec says.
    expect((new PipelineTestSuite('s', 'sch-rastreio-pedidos', 'test'))->endpointUrl('leomadeiras'))
        ->toBe('https://test.godigibee.io/pipeline/leomadeiras/v1/sch-rastreio-pedidos')
        ->and((new PipelineTestSuite('s', 'sch-rastreio-pedidos', 'prod', versionMajor: 3))->endpointUrl('leomadeiras'))
        ->toBe('https://api.godigibee.io/pipeline/leomadeiras/v3/sch-rastreio-pedidos');
});

it('refuses an environment it has no host for instead of defaulting to one', function () {
    // The dangerous failure this guards: with a fallback, a call for an
    // unmapped environment lands on production and gets reported as that
    // environment.
    expect(fn () => (new PipelineTestSuite('s', 'p', 'homolog'))->endpointUrl('leomadeiras'))
        ->toThrow(DigibeeApiException::class, 'No runtime host configured');
});

it('carries the major version off a pipeline read back from the platform', function () {
    $document = flowspecDocument([['id' => 'a', 'type' => 'connector', 'name' => 'log-connector']]);
    $document['versionMajor'] = 4;

    expect(matrixFor($document)->versionMajor)->toBe(4)
        ->and(matrixFor(flowspecDocument([]))->versionMajor)->toBe(1);
});

/*
|--------------------------------------------------------------------------
| The matrix builder
|--------------------------------------------------------------------------
*/

it('derives the request body from the {{ message.* }} references', function () {
    $suite = matrixFor(flowspecDocument([
        ['id' => 'a', 'type' => 'connector', 'name' => 'rest-v2-connector', 'stepName' => 'SAP', 'params' => [
            'url'  => 'https://sap.example/clientes/{{ message.cpf }}',
            'body' => '{"numero": "{{ message.pedido.numero }}", "cpf": "{{ message.cpf }}"}',
        ]],
    ]));

    $happy = caseNamed($suite, 'Caminho feliz');

    // 163 of the 201 real pipelines read at least one such field, which is why
    // this is derivation and not guesswork.
    expect($happy->body)->toBe(['cpf' => '<cpf>', 'pedido' => ['numero' => '<pedido.numero>']])
        // The placeholders name the field; they are not valid values, and the
        // case says so rather than pretending.
        ->and($happy->runnable())->toBeFalse()
        ->and($happy->blocked)->toContain('cpf', 'pedido.numero');
});

it('leaves the happy path runnable when the pipeline reads no input at all', function () {
    // A scheduled pipeline — 38 of the 201 read no `message.*` field.
    $suite = matrixFor(flowspecDocument([
        ['id' => 'a', 'type' => 'connector', 'name' => 'log-connector', 'stepName' => 'Log'],
    ]));

    $happy = caseNamed($suite, 'Caminho feliz');

    expect($happy->runnable())->toBeTrue()
        ->and($happy->body)->toBe([]);
});

it('emits one branch case per when condition plus one for otherwise, each naming its branch', function () {
    $suite = matrixFor(flowspecDocument([
        ['id' => 'a', 'type' => 'connector', 'name' => 'rest-v2-connector', 'stepName' => 'SAP'],
        ['id' => 'c', 'type' => 'choice', 'stepName' => 'Achou?', 'otherwise' => 'nao-encontrado', 'when' => [
            ['target' => 'encontrado', 'jsonPath' => '$.[?(@.status >= 200 && @.status <= 299)]'],
            ['target' => 'erro', 'simple' => "#{body.RETURNING.STATUS} != '200'"],
        ]],
    ], [
        'encontrado'     => [['id' => 'd', 'type' => 'connector', 'name' => 'log-connector']],
        'erro'           => [['id' => 'e', 'type' => 'connector', 'name' => 'log-connector']],
        'nao-encontrado' => [['id' => 'f', 'type' => 'connector', 'name' => 'log-connector']],
    ]));

    $branchCases = $suite->inCategory(TestCaseCategory::BranchCoverage);

    expect($branchCases)->toHaveCount(3)
        ->and(array_map(fn ($c) => $c->covers, $branchCases))->toBe(['encontrado', 'erro', 'nao-encontrado']);

    // Both conditions decide on the REST response, so neither is reachable
    // from a request payload — and each says which.
    expect($branchCases[0]->runnable())->toBeFalse()
        ->and($branchCases[0]->blocked)->toContain('saída de um step anterior')
        ->and($branchCases[1]->blocked)->toContain("#{body.RETURNING.STATUS} != '200'")
        ->and($branchCases[2]->blocked)->toContain('não satisfaça nenhuma condição');
});

it('solves the one solvable subset: a plain equality on the request body at the entry choice', function () {
    foreach ([
        ['simple' => "#{body.tipo} == 'ORCAMENTO'"],
        ['jsonPath' => "$.[?(@.tipo == 'ORCAMENTO')]"],
    ] as $condition) {
        $suite = matrixFor(flowspecDocument([
            ['id' => 'c', 'type' => 'choice', 'stepName' => 'Tipo', 'when' => [
                [...$condition, 'target' => 'orcamento'],
            ]],
            ['id' => 'z', 'type' => 'connector', 'name' => 'rest-v2-connector', 'params' => ['body' => '{{ message.tipo }}']],
        ], ['orcamento' => [['id' => 'd', 'type' => 'connector', 'name' => 'log-connector']]]));

        $branch = $suite->inCategory(TestCaseCategory::BranchCoverage)[0];

        expect($branch->runnable())->toBeTrue()
            ->and($branch->body)->toBe(['tipo' => 'ORCAMENTO']);
    }
});

it('inverts a not-equals condition into a value that says so', function () {
    $suite = matrixFor(flowspecDocument([
        ['id' => 'c', 'type' => 'choice', 'stepName' => 'Tipo', 'when' => [
            ['target' => 'outros', 'simple' => "#{body.tipo} != 'ORCAMENTO'"],
        ]],
    ], ['outros' => [['id' => 'd', 'type' => 'connector', 'name' => 'log-connector']]]));

    expect($suite->inCategory(TestCaseCategory::BranchCoverage)[0]->body)
        ->toBe(['tipo' => '<diferente de ORCAMENTO>']);
});

it('refuses to synthesize once a connector before the choice has rewritten the message', function () {
    $suite = matrixFor(flowspecDocument([
        // Object Store overwrites `message` (AGENTS.md says so out loud), so
        // `body.tipo` at this choice is its output, not the request.
        ['id' => 'o', 'type' => 'connector', 'name' => 'object-store-connector', 'stepName' => 'Busca'],
        ['id' => 'c', 'type' => 'choice', 'stepName' => 'Tipo', 'when' => [
            ['target' => 'orcamento', 'simple' => "#{body.tipo} == 'ORCAMENTO'"],
        ]],
    ], ['orcamento' => [['id' => 'd', 'type' => 'connector', 'name' => 'log-connector']]]));

    $branch = $suite->inCategory(TestCaseCategory::BranchCoverage)[0];

    expect($branch->runnable())->toBeFalse()
        ->and($branch->blocked)->toContain('saída de um step anterior');
});

it('still synthesizes when only a pass-through step precedes the choice', function () {
    $suite = matrixFor(flowspecDocument([
        ['id' => 'l', 'type' => 'connector', 'name' => 'log-connector', 'stepName' => 'Log'],
        ['id' => 'c', 'type' => 'choice', 'stepName' => 'Tipo', 'when' => [
            ['target' => 'orcamento', 'simple' => "#{body.tipo} == 'ORCAMENTO'"],
        ]],
    ], ['orcamento' => [['id' => 'd', 'type' => 'connector', 'name' => 'log-connector']]]));

    expect($suite->inCategory(TestCaseCategory::BranchCoverage)[0]->runnable())->toBeTrue();
});

it('reports one error-handler case per non-empty exception track, naming the step that owns it', function () {
    $suite = matrixFor(flowspecDocument([
        ['id' => 'fe', 'type' => 'connector', 'name' => 'for-each-connector', 'stepName' => 'Cada item', 'params' => [
            'onProcess'   => 'fe-onProcessTrack',
            'onException' => 'fe-onExceptionTrack',
        ]],
    ], [
        'fe-onProcessTrack'      => [['id' => 'p', 'type' => 'connector', 'name' => 'rest-v2-connector']],
        'fe-onExceptionTrack'    => [['id' => 'x', 'type' => 'connector', 'name' => 'log-connector']],
        'vazio-onExceptionTrack' => [],
    ]));

    $handlers = $suite->inCategory(TestCaseCategory::ErrorHandler);

    // An untested error handler is the most common silent gap in a pipeline's
    // coverage — omitting it would report full coverage of a flow whose
    // failure path nobody has run.
    expect($handlers)->toHaveCount(1)
        ->and($handlers[0]->name)->toBe('Tratamento de erro de «Cada item»')
        ->and($handlers[0]->covers)->toBe('fe-onExceptionTrack')
        ->and($handlers[0]->runnable())->toBeFalse()
        ->and($handlers[0]->blocked)->toContain('falhe');
});

it('emits the contract cases it can actually run unattended, capped at five missing fields', function () {
    $reads = collect(range(1, 8))->map(fn (int $i) => "{{ message.campo{$i} }}")->implode(' ');

    $suite = matrixFor(flowspecDocument([
        ['id' => 'a', 'type' => 'connector', 'name' => 'rest-v2-connector', 'params' => ['body' => $reads]],
    ]));

    $contract = $suite->inCategory(TestCaseCategory::Contract);

    expect($contract)->toHaveCount(7) // malformed + empty + 5 missing-field
        ->and(array_map(fn ($c) => $c->runnable(), $contract))->each->toBeTrue();

    $malformed = caseNamed($suite, 'Corpo malformado');

    expect($malformed->body)->toBeString()
        ->and($malformed->expects->describe())->toBe('qualquer status que não seja 5xx');

    $missing = caseNamed($suite, 'Sem o campo «campo1»');

    expect($missing->body)->not->toHaveKey('campo1')
        ->and($missing->body)->toHaveKey('campo2');
});

it('counts coverage as runnable against blocked, never as a case total', function () {
    $suite = matrixFor(flowspecDocument([
        ['id' => 'a', 'type' => 'connector', 'name' => 'rest-v2-connector', 'params' => ['body' => '{{ message.cpf }}']],
        ['id' => 'c', 'type' => 'choice', 'stepName' => 'Achou?', 'when' => [
            ['target' => 'erro', 'jsonPath' => '$.[?(@.status >= 400)]'],
        ]],
    ], ['erro' => [['id' => 'e', 'type' => 'connector', 'name' => 'log-connector']]]));

    $coverage = $suite->coverage();

    // happy path (blocked: needs a real cpf) + 1 branch (blocked) + 3 contract
    expect($coverage)->toBe(['runnable' => 3, 'blocked' => 2, 'total' => 5]);
});

it('understands both spellings of the entry branch', function () {
    // `start` is what all 201 live pipelines use; `disconnected-root:` is what
    // a generated document uses because it is written to be pasted.
    $ingested = [
        'meta'     => [],
        'flowSpec' => [
            'start' => [
                ['id' => 'c', 'type' => 'choice', 'stepName' => 'Tipo', 'when' => [
                    ['target' => 'a', 'simple' => "#{body.tipo} == 'X'"],
                ]],
            ],
            'a' => [['id' => 'd', 'type' => 'connector', 'name' => 'log-connector']],
        ],
    ];

    expect(matrixFor($ingested)->inCategory(TestCaseCategory::BranchCoverage)[0]->runnable())->toBeTrue();
});

it('names the suite after the pipeline', function () {
    expect(matrixFor(flowspecDocument([]), 'sch-Rastreio Pedidos')->name)
        ->toBe('sch-rastreio-pedidos-integration');
});

it('reads the input contract off the live flow, not off blocks left on the canvas', function () {
    // A pipeline read back from the platform carries disconnected blocks;
    // their references are not part of the flow's input contract.
    $document = flowspecDocument([
        ['id' => 'a', 'type' => 'connector', 'name' => 'rest-v2-connector', 'params' => ['body' => '{{ message.cpf }}']],
    ]);
    $document['metadata'] = ['disconnectedFlowSpecs' => [
        ['flowSpec' => ['disconnected-start' => [
            ['id' => 'z', 'type' => 'connector', 'name' => 'log-connector', 'params' => ['body' => '{{ message.campoMorto }}']],
        ]]],
    ]];

    expect(caseNamed(matrixFor($document), 'Caminho feliz')->body)->toBe(['cpf' => '<cpf>']);
});
