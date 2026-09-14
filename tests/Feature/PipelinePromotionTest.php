<?php

use App\Actions\Digibee\DeployPipeline;
use App\Actions\Digibee\PromotePipeline;
use App\Enums\DeploymentStatus;
use App\Enums\HealingVerdict;
use App\Support\Digibee\DeploymentReport;
use App\Support\Digibee\Healing\HealingReport;
use Illuminate\Support\Facades\Http;

/**
 * Bloco G — the gate, which is almost entirely refusals.
 *
 * Every test here asserts a REASON and not just a boolean, because the reason
 * is the product: a gate that answers "no" without saying which condition
 * failed sends somebody to read the code that refused them.
 */
function withPromotionConfig(array $overrides = []): void
{
    config()->set('services.digibee.design', array_merge([
        'endpoint' => 'https://core.example.test',
        'realm'    => 'leomadeiras',
        'jwt'      => promotionJwt(['useTokenACL' => true, 'roles' => [
            'PIPELINE:READ', 'DEPLOYMENT:CREATE{ENV=TEST}', 'DEPLOYMENT:CREATE{ENV=PROD}',
        ]]),
        'apikey'                  => 'a-key',
        'config_path'             => '',
        'timeout'                 => 30,
        'retries'                 => 1,
        'retry_sleep'             => 0,
        'runtime_hosts'           => ['test' => 'https://test.example.test', 'prod' => 'https://api.example.test'],
        'deployable_environments' => ['test', 'prod'],
    ], $overrides));
}

function promotionJwt(array $payload): string
{
    $encode = fn (array $part) => rtrim(strtr(base64_encode((string) json_encode($part)), '+/', '-_'), '=');

    return $encode(['alg' => 'HS256']) . '.' . $encode($payload) . '.sig';
}

function promotionDocument(string $message = 'oi'): array
{
    return ['meta' => [], 'flowSpec' => ['disconnected-root:11111111-1111-4111-8111-111111111111' => [
        [
            'id'       => '22222222-2222-4222-8222-222222222222',
            'type'     => 'connector',
            'name'     => 'log-connector',
            'stepName' => 'Log',
            'params'   => ['logLevel' => 'INFO', 'message' => $message],
        ],
    ]]];
}

/** What the realm answers when asked for the pipeline — the PLATFORM shape. */
function promotionStoredPipeline(string $message = 'oi'): array
{
    return [
        'id'           => 'pid-1',
        'name'         => 'meu-pipeline',
        'versionMajor' => 1,
        'versionMinor' => 0,
        'triggerSpec'  => ['type' => 'rest'],
        'flowSpec'     => ['start' => [
            [
                'id'       => '22222222-2222-4222-8222-222222222222',
                'type'     => 'connector',
                'name'     => 'log-connector',
                'stepName' => 'Log',
                'params'   => ['logLevel' => 'INFO', 'message' => $message],
            ],
        ]],
    ];
}

function promotionGreenEvidence(?array $document = null, string $environment = 'test'): HealingReport
{
    return new HealingReport(
        pipelineName: 'meu-pipeline',
        environment: $environment,
        verdict: HealingVerdict::Green,
        document: $document ?? promotionDocument(),
        endpoint: 'https://test.example.test/pipeline/leomadeiras/v1/meu-pipeline',
    );
}

/** A promoter whose deploy is a double, so no test can reach a real one. */
function promotionPromoter(?DeploymentReport $result = null): PromotePipeline
{
    $deploy = new class($result) extends DeployPipeline
    {
        public int $calls = 0;

        public function __construct(private ?DeploymentReport $result) {}

        public function handle(
            string $pipelineName,
            string $environment = 'test',
            string $size = 'SMALL',
            bool $redeploy = true,
            int $timeoutSeconds = 300,
            bool $dryRun = false,
        ): DeploymentReport {
            $this->calls++;

            return $this->result ?? new DeploymentReport(
                pipelineName: $pipelineName, environment: $environment,
                status: DeploymentStatus::Active, endpoint: 'https://api.example.test/pipeline/leomadeiras/v1/' . $pipelineName,
                deployed: true,
            );
        }
    };

    app()->instance('promotion.deploy.double', $deploy);

    return new PromotePipeline(
        app(App\Support\Digibee\DigibeeDesignClient::class),
        app(App\Support\Digibee\DigibeeAuthResolver::class),
        app(App\Services\Flowspec\DigibeeFlowspecNormalizer::class),
        $deploy,
    );
}

/**
 * The realm, answering with one pipeline (or none).
 *
 * Registered per test rather than in `beforeEach`, because `Http::fake()`
 * APPENDS stub callbacks instead of replacing them: a `'*'` registered first
 * matches first and a later `'*'` never runs. Setting a default here and
 * overriding it in the two tests that need a different answer silently gave
 * them the default — the drift test "passed" the gate against a pipeline it
 * thought it had changed.
 */
function promotionRealmHolding(array $pipelines): void
{
    Http::fake(['*' => Http::response($pipelines)]);
}

beforeEach(fn () => withPromotionConfig());

/*
|--------------------------------------------------------------------------
| The one path that promotes
|--------------------------------------------------------------------------
*/

it('promotes when every condition is met', function () {
    promotionRealmHolding([promotionStoredPipeline()]);

    $report = promotionPromoter()->handle(promotionGreenEvidence());

    expect($report->promoted)->toBeTrue()
        ->and($report->refused())->toBeFalse()
        ->and($report->toEnvironment)->toBe('prod')
        ->and(implode(' ', $report->warnings))->toContain('só o canvas');
});

it('judges without promoting on a dry run', function () {
    promotionRealmHolding([promotionStoredPipeline()]);
    $promoter = promotionPromoter();

    $report = $promoter->handle(promotionGreenEvidence(), dryRun: true);

    expect($report->refused())->toBeFalse()
        ->and($report->promoted)->toBeFalse()
        ->and(app('promotion.deploy.double')->calls)->toBe(0)
        ->and(implode(' ', $report->warnings))->toContain('Todas as condições passaram');
});

/*
|--------------------------------------------------------------------------
| The refusals
|--------------------------------------------------------------------------
*/

it('refuses a target the configuration does not open', function () {
    // The default state of this app, and the correct one: `prod` is not in the
    // list, so every promotion refuses until a person edits configuration.
    withPromotionConfig(['deployable_environments' => ['test']]);
    promotionRealmHolding([promotionStoredPipeline()]);

    $report = promotionPromoter()->handle(promotionGreenEvidence());

    expect($report->promoted)->toBeFalse()
        ->and(implode(' ', $report->refusals))->toContain('não está em deployable_environments')
        ->and(app('promotion.deploy.double')->calls)->toBe(0);
});

it('refuses to promote an environment to itself', function () {
    promotionRealmHolding([promotionStoredPipeline()]);

    $report = promotionPromoter()->handle(promotionGreenEvidence(), toEnvironment: 'test');

    expect(implode(' ', $report->refusals))->toContain('redeploy autorizado por si mesmo');
});

it('refuses evidence that is not green', function () {
    $evidence = new HealingReport(
        pipelineName: 'meu-pipeline', environment: 'test',
        verdict: HealingVerdict::StillFailing, document: promotionDocument(),
    );
    promotionRealmHolding([promotionStoredPipeline()]);

    expect(implode(' ', promotionPromoter()->handle($evidence)->refusals))
        ->toContain('A evidência não é verde');
});

it('says when the evidence was not even about the pipeline', function () {
    // A wall of 401s passes no case and fails none — the tally alone would
    // look like nothing is wrong.
    $evidence = new HealingReport(
        pipelineName: 'meu-pipeline', environment: 'test',
        verdict: HealingVerdict::RefusedAtTheDoor, document: promotionDocument(),
    );
    promotionRealmHolding([promotionStoredPipeline()]);

    expect(implode(' ', promotionPromoter()->handle($evidence)->refusals))
        ->toContain('não chega a ser uma conclusão sobre o pipeline');
});

it('refuses when the token declares no deploy permission for the target', function () {
    withPromotionConfig(['jwt' => promotionJwt(['useTokenACL' => true, 'roles' => [
        'PIPELINE:READ', 'DEPLOYMENT:CREATE{ENV=TEST}',
    ]])]);
    promotionRealmHolding([promotionStoredPipeline()]);

    $report = promotionPromoter()->handle(promotionGreenEvidence());

    expect(implode(' ', $report->refusals))->toContain('não declara permissão de deploy em "prod"')
        ->and(app('promotion.deploy.double')->calls)->toBe(0);
});

it('refuses when the stored pipeline is no longer the one that was tested', function () {
    // The check none of the others can stand in for: the canvas writes
    // directly, so a pipeline can change between the green run and the
    // promotion, and nothing else here would notice.
    promotionRealmHolding([promotionStoredPipeline('alterado no canvas')]);

    $report = promotionPromoter()->handle(promotionGreenEvidence());

    expect(implode(' ', $report->refusals))->toContain('não é mais o que foi testado')
        ->and(app('promotion.deploy.double')->calls)->toBe(0);
});

it('does not read the generator shape as drift from the stored one', function () {
    promotionRealmHolding([promotionStoredPipeline()]);

    // The tested document roots at `disconnected-root:<uuid>` and carries a
    // canvas `meta`; the stored one roots at `start` and has neither. Comparing
    // them raw would refuse every correct promotion.
    $report = promotionPromoter()->handle(promotionGreenEvidence(promotionDocument()));

    expect($report->refusals)->toBe([])
        ->and($report->promoted)->toBeTrue();
});

it('refuses evidence carrying no document, since nothing ties it to what is stored', function () {
    $evidence = new HealingReport(
        pipelineName: 'meu-pipeline', environment: 'test',
        verdict: HealingVerdict::Green, document: null,
    );
    promotionRealmHolding([promotionStoredPipeline()]);

    expect(implode(' ', promotionPromoter()->handle($evidence)->refusals))
        ->toContain('não carrega o documento que foi testado');
});

it('refuses a pipeline the realm does not have', function () {
    promotionRealmHolding([]);

    expect(implode(' ', promotionPromoter()->handle(promotionGreenEvidence())->refusals))
        ->toContain('Nenhum pipeline chamado');
});

/*
|--------------------------------------------------------------------------
| Reporting
|--------------------------------------------------------------------------
*/

it('reports every failed condition, not just the first', function () {
    withPromotionConfig([
        'deployable_environments' => ['test'],
        'jwt' => promotionJwt(['useTokenACL' => true, 'roles' => ['PIPELINE:READ']]),
    ]);

    $evidence = new HealingReport(
        pipelineName: 'meu-pipeline', environment: 'test',
        verdict: HealingVerdict::Unproven, document: promotionDocument('outro'),
    );
    promotionRealmHolding([promotionStoredPipeline()]);

    $refusals = promotionPromoter()->handle($evidence)->refusals;

    // Environment, verdict, permission and drift — somebody asking why this is
    // not in production gets the whole list rather than four round trips.
    expect($refusals)->toHaveCount(4);
});

it('reports a deploy that refused as a refusal of the promotion', function () {
    $broken = new DeploymentReport(
        pipelineName: 'meu-pipeline', environment: 'prod',
        errors: ['Nenhuma configuração small para o ambiente "prod".'],
    );

    promotionRealmHolding([promotionStoredPipeline()]);

    $report = promotionPromoter($broken)->handle(promotionGreenEvidence());

    expect($report->promoted)->toBeFalse()
        ->and(implode(' ', $report->refusals))->toContain('Nenhuma configuração small');
});

/*
|--------------------------------------------------------------------------
| What a server gets when nobody configures anything
|--------------------------------------------------------------------------
*/

it('ships with production closed, as a default rather than a setting to remember', function () {
    // Read from the SHIPPED config file with the variable unset, because that
    // is the state of a box where nobody added it — and the guardrail is only
    // a guardrail if it holds there. Asserting `config()` instead would assert
    // this test environment.
    $keys = ['DIGIBEE_DEPLOYABLE_ENVIRONMENTS', 'DIGIBEE_MAX_HEALING_ROUNDS'];
    $saved = [];

    foreach ($keys as $key) {
        $saved[$key] = [$_ENV[$key] ?? null, $_SERVER[$key] ?? null, getenv($key)];
        unset($_ENV[$key], $_SERVER[$key]);
        putenv($key);
    }

    try {
        $services = require base_path('config/services.php');

        expect($services['digibee']['design']['deployable_environments'])->toBe(['test'])
            ->and($services['digibee']['design']['max_healing_rounds'])->toBe(3);
    } finally {
        foreach ($keys as $key) {
            [$env, $server, $raw] = $saved[$key];

            if ($env !== null) {
                $_ENV[$key] = $env;
            }

            if ($server !== null) {
                $_SERVER[$key] = $server;
            }

            if ($raw !== false) {
                putenv("{$key}={$raw}");
            }
        }
    }
});
