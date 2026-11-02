<?php

use App\Exceptions\DigibeeApiException;
use App\Support\Digibee\DigibeeDesignClient;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;

/**
 * Which failures a Digibee WRITE is sent again after.
 *
 * Creating a pipeline is not repeatable: a create whose answer was lost already
 * made the pipeline, and a second POST makes a twin with the same name that
 * nothing in the platform can delete. So it is retried only when the request
 * provably never landed. Upserting is keyed by id and keeps the ordinary retry.
 */
function writeRetryConfig(): void
{
    config()->set('services.digibee.design', array_merge(config('services.digibee.design', []), [
        'endpoint'    => 'https://core.example.test',
        'realm'       => 'leomadeiras',
        'jwt'         => 'a.b.c',
        'apikey'      => 'a-key',
        'config_path' => '',
        'timeout'     => 30,
        // Two attempts, so a retry is observable.
        'retries'     => 2,
        'retry_sleep' => 0,
    ]));
}

/** Fakes the platform: `$first` answers the first POST, a success every later one. */
function fakeFirstPost(callable|array $first, int &$posts): void
{
    Http::fake(function (Request $request) use ($first, &$posts) {
        if ($request->method() !== 'POST') {
            return Http::response([], 200);
        }

        $posts++;

        if ($posts > 1) {
            return Http::response(['pipeline' => ['id' => 'pipe-1']], 200);
        }

        return is_callable($first) ? $first($request) : Http::response($first[0], $first[1]);
    });
}

beforeEach(fn () => writeRetryConfig());

it('never sends a create again after a 502 — the platform may already have made it', function () {
    $posts = 0;
    fakeFirstPost([['message' => 'bad gateway'], 502], $posts);

    expect(fn () => app(DigibeeDesignClient::class)->create('meu-pipeline'))
        ->toThrow(DigibeeApiException::class)
        ->and($posts)->toBe(1);
});

it('never sends a create again after a READ timeout', function () {
    $posts = 0;
    fakeFirstPost(Http::failedConnection('cURL error 28: Operation timed out after 30001 milliseconds with 0 bytes received'), $posts);

    expect(fn () => app(DigibeeDesignClient::class)->create('meu-pipeline'))
        ->toThrow(ConnectionException::class)
        ->and($posts)->toBe(1);
});

it('retries a create the platform never received', function (callable|array $first) {
    $posts = 0;
    fakeFirstPost($first, $posts);

    expect(app(DigibeeDesignClient::class)->create('meu-pipeline'))->toBe('pipe-1')
        ->and($posts)->toBe(2);
})->with([
    'host never resolved' => fn () => Http::failedConnection(),
    'connect timed out'   => fn () => Http::failedConnection('cURL error 28: Connection timed out after 5001 milliseconds'),
    'told to come back'   => [[['message' => 'slow down'], 429]],
    'unavailable'         => [[['message' => 'unavailable'], 503]],
]);

it('keeps retrying an upsert after a 502, since it is keyed by id', function () {
    $posts = 0;
    fakeFirstPost([['message' => 'bad gateway'], 502], $posts);

    app(DigibeeDesignClient::class)->upsert(['id' => 'pipe-1', 'name' => 'meu-pipeline']);

    expect($posts)->toBe(2);
});
