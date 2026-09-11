<?php

use App\Actions\Digibee\DeployPipeline;
use App\Enums\DeploymentStatus;
use App\Exceptions\DigibeeApiException;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Sleep;

/**
 * Block D — deploying, with the network faked.
 *
 * The fake matters more here than anywhere else in this feature: a real run of
 * these cases deploys to a realm running 201 live integrations.
 */
function withDeployConfig(array $overrides = []): void
{
    config()->set('services.digibee.design', array_merge([
        'endpoint' => 'https://core.example.test',
        'realm'    => 'leomadeiras',
        'jwt'      => deployJwt(['useTokenACL' => true, 'roles' => [
            'PIPELINE:READ', 'DEPLOYMENT:READ{ENV=TEST}', 'DEPLOYMENT:CREATE{ENV=TEST}',
            'DEPLOYMENT:CREATE:REDEPLOY{ENV=TEST}',
        ]]),
        'apikey'                  => 'a-key',
        'config_path'             => '',
        'timeout'                 => 30,
        'retries'                 => 1,
        'retry_sleep'             => 0,
        'runtime_hosts'           => ['test' => 'https://test.example.test', 'prod' => 'https://api.example.test'],
        'deployable_environments' => ['test'],
    ], $overrides));
}

function deployJwt(array $payload): string
{
    $encode = fn (array $part) => rtrim(strtr(base64_encode((string) json_encode($part)), '+/', '-_'), '=');

    return $encode(['alg' => 'HS256']) . '.' . $encode($payload) . '.sig';
}

function deploymentRow(string $status = 'SERVICE_ACTIVE', ?string $endpoint = 'https://test.example.test/pipeline/leomadeiras/v1/meu-pipeline'): array
{
    return [
        'id'                   => 'dep-1',
        'pipelineName'         => 'meu-pipeline',
        'pipelineMajorVersion' => 1,
        'status'               => $status,
        'activeConfiguration'  => ['name' => 'small-meu-pipeline'],
        'deploymentStatus'     => [
            'availableReplicas'  => '1/1',
            'error-count'        => '0',
            'oom-count'          => '0',
            'last-error-message' => null,
            'trigger'            => $endpoint === null
                ? null
                : json_encode([['key' => 'endpoint', 'value' => $endpoint]]),
        ],
    ];
}

/**
 * The six configurations every pipeline has: three sizes times two
 * environments, each naming its own.
 *
 * @return list<array<string, mixed>>
 */
function deployConfigurations(): array
{
    $rows = [];

    foreach (['small', 'medium', 'large'] as $size) {
        foreach (['test', 'prod'] as $environment) {
            $rows[] = [
                'id'          => "cfg-{$size}-{$environment}",
                'name'        => "{$size}-meu-pipeline",
                'environment' => ['name' => $environment],
            ];
        }
    }

    return $rows;
}

/** Design list, design detail, configurations, runtime list, runtime POST. */
function fakeDeployApi(array $deployments, array $pipeline = ['id' => 'pid-1', 'name' => 'meu-pipeline', 'versionMajor' => 1, 'versionMinor' => 0, 'triggerSpec' => ['type' => 'rest']]): void
{
    Http::fake(function (Request $request) use ($deployments, $pipeline) {
        if (str_contains($request->url(), '/runtime/')) {
            return $request->method() === 'POST'
                ? Http::response(['id' => 'dep-1'])
                : Http::response($deployments);
        }

        if (str_contains($request->url(), '/configurations')) {
            return Http::response(deployConfigurations());
        }

        return Http::response([$pipeline]);
    });
}

beforeEach(fn () => Sleep::fake());

it('deploys and reports the endpoint the platform assigned', function () {
    withDeployConfig();
    fakeDeployApi([deploymentRow()]);

    $report = app(DeployPipeline::class)->handle('meu-pipeline');

    // Composing the URL is the fallback; when the platform has said it, the
    // platform wins — that is what keeps a "test" call out of production.
    expect($report->live())->toBeTrue()
        ->and($report->testable())->toBeTrue()
        ->and($report->endpoint)->toBe('https://test.example.test/pipeline/leomadeiras/v1/meu-pipeline')
        ->and($report->status)->toBe(DeploymentStatus::Active);
});

it('refuses an environment outside the configured list, before any call', function () {
    withDeployConfig();
    Http::fake();

    expect(fn () => app(DeployPipeline::class)->handle('meu-pipeline', 'prod'))
        ->toThrow(DigibeeApiException::class, 'reaches real traffic');

    Http::assertNothingSent();
});

it('reads the token ACL and refuses before the platform has to', function () {
    withDeployConfig(['jwt' => deployJwt(['useTokenACL' => true, 'roles' => ['PIPELINE:READ', 'DEPLOYMENT:READ{ENV=TEST}']])]);
    Http::fake();

    $report = app(DeployPipeline::class)->handle('meu-pipeline');

    expect($report->ok())->toBeFalse()
        ->and(implode(' ', $report->errors))->toContain('não declara permissão de deploy');

    Http::assertNothingSent();
});

it('accepts an environment-scoped grant for the environment asked for', function () {
    withDeployConfig(['jwt' => deployJwt(['useTokenACL' => true, 'roles' => ['DEPLOYMENT:CREATE{ENV=TEST}']])]);
    fakeDeployApi([deploymentRow()]);

    expect(app(DeployPipeline::class)->handle('meu-pipeline')->ok())->toBeTrue();
});

it('leaves an interactive session to the platform to judge', function () {
    // No roles declared: the credential carries the user's own role instead,
    // and guessing at it here would refuse a deploy that would have worked.
    withDeployConfig(['jwt' => deployJwt(['sub' => 'someone'])]);
    fakeDeployApi([deploymentRow()]);

    expect(app(DeployPipeline::class)->handle('meu-pipeline')->ok())->toBeTrue();
});

it('waits for a settling deployment and stops when it settles', function () {
    withDeployConfig();
    $answers = [[deploymentRow('STARTING')], [deploymentRow('STARTING')], [deploymentRow('SERVICE_ACTIVE')]];

    Http::fake(function (Request $request) use (&$answers) {
        if (str_contains($request->url(), '/runtime/')) {
            return $request->method() === 'POST'
                ? Http::response(['id' => 'dep-1'])
                : Http::response(count($answers) > 1 ? array_shift($answers) : $answers[0]);
        }

        if (str_contains($request->url(), '/configurations')) {
            return Http::response(deployConfigurations());
        }

        return Http::response([['id' => 'pid-1', 'name' => 'meu-pipeline', 'versionMajor' => 1, 'triggerSpec' => ['type' => 'rest']]]);
    });

    $report = app(DeployPipeline::class)->handle('meu-pipeline');

    expect($report->live())->toBeTrue()
        ->and($report->waitedSeconds)->toBeGreaterThan(0);
});

it('gives up with a third answer when the deployment never settles', function () {
    withDeployConfig();
    fakeDeployApi([deploymentRow('STARTING')]);

    $report = app(DeployPipeline::class)->handle('meu-pipeline', timeoutSeconds: 9);

    // Not refused, not broken: still settling. Collapsing this into either is
    // how a loop starts rewriting a pipeline that was merely slow.
    expect($report->deployed)->toBeTrue()
        ->and($report->live())->toBeFalse()
        ->and($report->ok())->toBeTrue()
        ->and(implode(' ', $report->warnings))->toContain('não estabilizou');
});

it('reports a deployment that came up broken as an error, with the engine message', function () {
    withDeployConfig();
    $row = deploymentRow('SERVICE_ERROR');
    $row['deploymentStatus']['last-error-message'] = 'connection refused';
    fakeDeployApi([$row]);

    $report = app(DeployPipeline::class)->handle('meu-pipeline');

    expect($report->live())->toBeFalse()
        ->and($report->ok())->toBeFalse()
        ->and(implode(' ', $report->warnings))->toContain('connection refused');
});

it('warns when the pipeline has no trigger, since nothing can call it', function () {
    withDeployConfig();
    fakeDeployApi([deploymentRow()], ['id' => 'pid-1', 'name' => 'meu-pipeline', 'versionMajor' => 1, 'triggerSpec' => []]);

    expect(implode(' ', app(DeployPipeline::class)->handle('meu-pipeline')->warnings))
        ->toContain('não tem triggerSpec');
});

it('does not treat a parked replica count as a failure', function () {
    withDeployConfig();
    $row = deploymentRow();
    // "0/0" is the ordinary state for 81 of the 111 deployments in test —
    // autoscaling parked them.
    $row['deploymentStatus']['availableReplicas'] = '0/0';
    fakeDeployApi([$row]);

    $report = app(DeployPipeline::class)->handle('meu-pipeline');

    expect($report->live())->toBeTrue()
        ->and($report->engine['replicas'])->toBe('0/0');
});

it('reports what it would send on a dry run, without deploying', function () {
    withDeployConfig();
    fakeDeployApi([deploymentRow()]);

    $report = app(DeployPipeline::class)->handle('meu-pipeline', dryRun: true);

    expect($report->deployed)->toBeFalse()
        ->and(implode(' ', $report->warnings))->toContain('pipelineId, runtimeConfigurationId');

    Http::assertNotSent(fn (Request $request) => $request->method() === 'POST');
});

it('refuses a pipeline the realm does not have', function () {
    withDeployConfig();
    fakeDeployApi([], []);
    Http::fake(function (Request $request) {
        return Http::response(str_contains($request->url(), '/runtime/') ? [] : []);
    });

    $report = app(DeployPipeline::class)->handle('nao-existe');

    expect($report->ok())->toBeFalse()
        ->and(implode(' ', $report->errors))->toContain('Nenhum pipeline');
});

it('dry-runs a pipeline that has never been deployed', function () {
    withDeployConfig();
    fakeDeployApi([]); // no deployment at all — the state every first deploy is in

    $report = app(DeployPipeline::class)->handle('meu-pipeline', dryRun: true);

    expect($report->ok())->toBeTrue()
        ->and($report->deploymentId)->toBeNull()
        ->and($report->status)->toBe(DeploymentStatus::Unknown);
});

it('sends the environment in the query string, where the permission check reads it', function () {
    withDeployConfig();
    fakeDeployApi([deploymentRow()]);

    app(DeployPipeline::class)->handle('meu-pipeline');

    // Three identical 403s came from putting this in the body: the check runs
    // before the handler and is environment-scoped, so a request whose
    // environment the server cannot see is evaluated against nothing.
    Http::assertSent(fn (Request $request) => $request->method() === 'POST'
        && str_contains($request->url(), 'environment=test')
        && ! array_key_exists('environment', $request->data()));
});

it('deploys the LATEST version row, which is what the platform accepts', function () {
    withDeployConfig();
    // A v0 row deploys — the canvas proved it. What answers 404 "No such
    // entity" is an OLD version's id, since every version is its own document.
    fakeDeployApi([deploymentRow()], ['id' => 'pid-1', 'name' => 'meu-pipeline', 'versionMajor' => 0, 'versionMinor' => 2, 'triggerSpec' => ['type' => 'rest']]);

    $report = app(DeployPipeline::class)->handle('meu-pipeline');

    expect($report->ok())->toBeTrue();
    Http::assertSent(fn (Request $request) => $request->method() === 'POST'
        && ($request->data()['pipelineId'] ?? null) === 'pid-1');
});

it('names the pipeline by `pipelineId`, the key that resolves it', function () {
    withDeployConfig();
    fakeDeployApi([deploymentRow()]);

    app(DeployPipeline::class)->handle('meu-pipeline');

    // With `pipelineId` the handler resolves the pipeline — it got as far as
    // validating that pipeline's trigger spec. With `id` it answers 404 "No
    // such entity" whatever the value.
    Http::assertSent(fn (Request $request) => $request->method() === 'POST'
        && ($request->data()['pipelineId'] ?? null) === 'pid-1');
});

it('deploys with the configuration for that size AND environment', function () {
    withDeployConfig();
    fakeDeployApi([deploymentRow()]);

    app(DeployPipeline::class)->handle('meu-pipeline', size: 'MEDIUM');

    // The environment lives in the query string; the CONFIGURATION carries one
    // too, and sending prod's id from a request aimed at test would land
    // production settings. Both have to agree.
    Http::assertSent(fn (Request $request) => $request->method() === 'POST'
        && ($request->data()['runtimeConfigurationId'] ?? null) === 'cfg-medium-test');
});

it('refuses when no configuration matches, instead of taking the first', function () {
    withDeployConfig();
    fakeDeployApi([deploymentRow()]);

    // A size the pipeline has no configuration for. Falling back to the first
    // row would deploy with settings nobody asked for — and one of the six is
    // production's.
    $report = app(DeployPipeline::class)->handle('meu-pipeline', size: 'XLARGE');

    expect($report->ok())->toBeFalse()
        ->and(implode(' ', $report->errors))->toContain('Nenhuma configuração');

    Http::assertNotSent(fn (Request $request) => $request->method() === 'POST');
});
