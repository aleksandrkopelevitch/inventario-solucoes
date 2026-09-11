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

/** Design list, design detail, runtime list, runtime POST — all four in one fake. */
function fakeDeployApi(array $deployments, array $pipeline = ['id' => 'pid-1', 'name' => 'meu-pipeline', 'versionMajor' => 1, 'versionMinor' => 0, 'triggerSpec' => ['type' => 'rest']]): void
{
    Http::fake(function (Request $request) use ($deployments, $pipeline) {
        if (str_contains($request->url(), '/runtime/')) {
            return $request->method() === 'POST'
                ? Http::response(['id' => 'dep-1'])
                : Http::response($deployments);
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
        ->and(implode(' ', $report->warnings))->toContain('pipelineId');

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
