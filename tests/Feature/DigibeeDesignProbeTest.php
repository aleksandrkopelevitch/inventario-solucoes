<?php

use App\Actions\Digibee\ProbeDigibeeDesignApi;
use App\Exceptions\DigibeeApiException;
use App\Support\Digibee\DigibeeAuthResolver;
use App\Support\Digibee\DigibeeProbeReport;
use Illuminate\Support\Facades\Http;

/**
 * Everything here fakes the HTTP layer. That is not only about speed: these
 * routes are undocumented and live on the platform that runs 201 real
 * integrations, so a suite that reached them would be a suite that deploys
 * something the day somebody widens a fake.
 */
function digibeeConfigFile(array $document): string
{
    $path = sys_get_temp_dir() . '/digibeectl-' . uniqid() . '.json';
    file_put_contents($path, json_encode($document));

    return $path;
}

function withDigibeeEnvironment(array $overrides = []): void
{
    config()->set('services.digibee.design', array_merge([
        'endpoint'                => 'https://core.example.test',
        'realm'                   => 'leomadeiras',
        'jwt'                     => 'header.payload.signature',
        'apikey'                  => 'an-api-key',
        'config_path'             => '',
        'timeout'                 => 30,
        'retries'                 => 1,
        'retry_sleep'             => 0,
        'runtime_url'             => 'https://api.example.test',
        'deployable_environments' => ['test'],
    ], $overrides));
}

/*
|--------------------------------------------------------------------------
| Credential resolution
|--------------------------------------------------------------------------
*/

it('resolves the credential from the environment and reports it as the source', function () {
    withDigibeeEnvironment();

    $credentials = app(DigibeeAuthResolver::class)->resolve();

    expect($credentials->complete())->toBeTrue()
        ->and($credentials->realm)->toBe('leomadeiras')
        ->and($credentials->sources['jwt'])->toBe('environment');
});

it('prefers the environment over a digibeectl config file that also holds a session', function () {
    withDigibeeEnvironment(['config_path' => digibeeConfigFile([
        'endpoint' => 'https://from-the-file.example.test',
        'jwt'      => 'file-jwt',
    ])]);

    $credentials = app(DigibeeAuthResolver::class)->resolve();

    // The server must never pick up a developer's broader credential from a
    // file somebody synced onto it.
    expect($credentials->endpoint)->toBe('https://core.example.test')
        ->and($credentials->jwt)->toBe('header.payload.signature');
});

it('reads a nested digibeectl config and picks the account matching the current realm', function () {
    withDigibeeEnvironment([
        'endpoint'    => null,
        'realm'       => null,
        'jwt'         => null,
        'apikey'      => null,
        'config_path' => digibeeConfigFile([
            'endpoint'     => 'https://core.example.test',
            'currentRealm' => 'leomadeiras',
            'accounts'     => [
                'account-0' => ['realm' => 'outra-realm', 'jwt' => 'wrong-jwt', 'apikey' => 'wrong-key'],
                'account-1' => ['realm' => 'leomadeiras', 'jwt' => 'right-jwt', 'apikey' => 'right-key'],
            ],
        ]),
    ]);

    $credentials = app(DigibeeAuthResolver::class)->resolve();

    expect($credentials->jwt)->toBe('right-jwt')
        ->and($credentials->realm)->toBe('leomadeiras')
        // The reported path is what makes a tolerant parser auditable: it says
        // WHICH nested object it believed, instead of silently guessing.
        ->and($credentials->sources['jwt'])->toBe('digibeectl config (accounts.account-1.jwt)')
        // `endpoint` legitimately comes from the root while the token comes
        // from the account.
        ->and($credentials->sources['endpoint'])->toBe('digibeectl config (endpoint)');
});

it('names every missing field instead of failing on the first one', function () {
    withDigibeeEnvironment(['jwt' => null, 'apikey' => null]);

    $credentials = app(DigibeeAuthResolver::class)->resolve();

    expect($credentials->complete())->toBeFalse()
        ->and($credentials->missing())->toBe(['jwt', 'apikey']);

    expect(fn () => app(DigibeeAuthResolver::class)->credentials())
        ->toThrow(DigibeeApiException::class, 'missing: jwt, apikey');
});

it('sends the JWT raw, with no Bearer prefix', function () {
    withDigibeeEnvironment();
    Http::fake(['*' => Http::response([])]);

    Http::digibeeDesign()->get('/design/realms/leomadeiras/pipelines');

    Http::assertSent(function ($request) {
        // withToken() would prepend "Bearer " and answer 401 on a token that
        // is perfectly valid — the trap §3.1 of the spec spells out.
        expect($request->header('Authorization'))->toBe(['header.payload.signature'])
            ->and($request->header('apikey'))->toBe(['an-api-key']);

        return true;
    });
});

it('never puts a credential value in the diagnostic output', function () {
    withDigibeeEnvironment();

    $rows = app(DigibeeAuthResolver::class)->resolve()->diagnose();
    $flat = json_encode($rows);

    expect($flat)->not->toContain('header.payload.signature')
        ->and($flat)->not->toContain('an-api-key')
        // A length is the one property worth showing: a token truncated by a
        // shell quoting mistake answers 401 exactly like an expired one.
        ->and(collect($rows)->firstWhere('field', 'jwt')['length'])->toBe(24);
});

/*
|--------------------------------------------------------------------------
| The probe
|--------------------------------------------------------------------------
*/

it('reads the three routes and confirms a pipeline round-trips', function () {
    withDigibeeEnvironment();

    Http::fake([
        '*/pipelines'    => Http::response(['content' => [['id' => 'pipe-1', 'name' => 'sch-rastreio']], 'last' => true]),
        '*/pipelines/*'  => Http::response(array_fill_keys(DigibeeProbeReport::ROUND_TRIP_KEYS, 'x')),
        '*/deployments*' => Http::response([['pipelineName' => 'sch-rastreio', 'status' => 'Deployed']]),
    ]);

    $report = app(ProbeDigibeeDesignApi::class)->handle();

    expect($report->ok())->toBeTrue()
        ->and($report->reachedDetail)->toBeTrue()
        ->and($report->missingRoundTripKeys())->toBe([])
        ->and($report->verdict())->toContain('ingestion is reachable')
        // The id came out of digibeectl's own pagination envelope, which is
        // one of three shapes this route might use.
        ->and($report->steps[1]['path'])->toBe('/design/realms/leomadeiras/pipelines/pipe-1');
});

it('refuses to call a partial read a success when the detail omits flowSpec', function () {
    withDigibeeEnvironment();

    Http::fake([
        '*/pipelines'    => Http::response([['id' => 'pipe-1']]),
        '*/pipelines/*'  => Http::response(['id' => 'pipe-1', 'name' => 'sch-rastreio', 'projectId' => 'p1']),
        '*/deployments*' => Http::response([]),
    ]);

    $report = app(ProbeDigibeeDesignApi::class)->handle();

    // Every route answered 200, and the loop is still not reachable: reading a
    // pipeline proves the route exists, not that a flowSpec goes back through it.
    expect($report->ok())->toBeTrue()
        ->and($report->missingRoundTripKeys())->toContain('flowSpec', 'triggerSpec')
        ->and($report->verdict())->toContain('omits');
});

it('reports a 404 as a finding rather than throwing, and skips the detail read', function () {
    withDigibeeEnvironment();

    Http::fake([
        '*/pipelines'    => Http::response(['message' => 'no such route'], 404),
        '*/deployments*' => Http::response([]),
    ]);

    $report = app(ProbeDigibeeDesignApi::class)->handle();

    expect($report->ok())->toBeFalse()
        ->and($report->steps[0]['status'])->toBe(404)
        ->and($report->steps[0]['note'])->toContain('devtools')
        ->and($report->steps[1]['note'])->toContain('skipped')
        // The deployment route is still probed: three separate pieces of news
        // in one run is the whole point of reporting instead of throwing.
        ->and($report->steps[2]['status'])->toBe(200);
});

it('makes no network call at all when the credential is incomplete', function () {
    withDigibeeEnvironment(['jwt' => null]);
    Http::fake();

    $report = app(ProbeDigibeeDesignApi::class)->handle();

    expect($report->steps)->toBe([])
        ->and($report->verdict())->toContain('nothing was called');

    Http::assertNothingSent();
});

it('only ever issues GET requests', function () {
    withDigibeeEnvironment();

    Http::fake([
        '*/pipelines'    => Http::response([['id' => 'pipe-1']]),
        '*/pipelines/*'  => Http::response(['id' => 'pipe-1', 'flowSpec' => []]),
        '*/deployments*' => Http::response([]),
    ]);

    app(ProbeDigibeeDesignApi::class)->handle(environment: 'test');

    // The guard that matters: this runs against a realm holding 201 live
    // integrations, and `create deployment -e prod` is one verb away.
    Http::assertSent(fn ($request) => $request->method() === 'GET');
});

it('reports what resolved without calling anything under --diagnose', function () {
    withDigibeeEnvironment();
    Http::fake();

    $this->artisan('digibee:design:probe', ['--diagnose' => true])
        ->expectsOutputToContain('environment')
        ->assertSuccessful();

    Http::assertNothingSent();
});

it('exits non-zero when a probed route did not answer as the spec assumes', function () {
    withDigibeeEnvironment();

    Http::fake([
        '*/pipelines'    => Http::response([], 403),
        '*/deployments*' => Http::response([], 403),
    ]);

    $this->artisan('digibee:design:probe')->assertFailed();
});
