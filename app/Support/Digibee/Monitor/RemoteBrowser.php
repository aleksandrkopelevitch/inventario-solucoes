<?php

namespace App\Support\Digibee\Monitor;

use Illuminate\Http\Client\PendingRequest;
use Illuminate\Http\Client\Response;
use Illuminate\Support\Facades\Http;

/**
 * The bridge `scripts/digibee-remote-sign-in.mjs` serves on 127.0.0.1 while a
 * remote sign-in runs: the page as a JPEG, the person's clicks and keys into
 * it, the run's state, and — once — the captured headers.
 *
 * Local and loopback-only, so not one of the `Http::` macros (those are for
 * external APIs); the timeouts are explicit all the same. What `input()`
 * carries is typed by a person signing in — a password among it — so nothing
 * here logs a body or keeps one.
 */
final class RemoteBrowser
{
    public function __construct(
        private readonly int $port,
        #[\SensitiveParameter] private readonly string $secret,
    ) {}

    /** JPEG bytes, or null while the page cannot be drawn. */
    public function frame(): ?string
    {
        $response = $this->send(fn (PendingRequest $request) => $request->get('/frame'));

        return $response?->successful() ? $response->body() : null;
    }

    /** @param array<string, mixed> $event */
    public function input(array $event): bool
    {
        return (bool) $this->send(fn (PendingRequest $request) => $request->post('/input', $event))?->successful();
    }

    /** @return array{status: string, message: ?string, host: ?string}|null */
    public function state(): ?array
    {
        $response = $this->send(fn (PendingRequest $request) => $request->get('/state'));

        return $response?->successful() ? $response->json() : null;
    }

    /** The captured session, handed over exactly once by the bridge. */
    public function session(): ?MonitorSession
    {
        $response = $this->send(fn (PendingRequest $request) => $request->get('/session'));

        if (! $response?->successful()
            || ! is_string($response->json('authorization'))
            || ! is_string($response->json('apikey'))) {
            return null;
        }

        return new MonitorSession($response->json('authorization'), $response->json('apikey'));
    }

    public function quit(): void
    {
        $this->send(fn (PendingRequest $request) => $request->post('/quit'));
    }

    /**
     * A bridge that is gone answers null instead of throwing: the browser
     * closing (signed in, timed out, cancelled) is the normal end of a run,
     * and the screen learns it from the run's status, not from a 500 here.
     */
    private function send(\Closure $call): ?Response
    {
        return rescue(fn () => $call($this->request()), report: false);
    }

    private function request(): PendingRequest
    {
        return Http::baseUrl('http://127.0.0.1:' . $this->port)
            ->withHeaders(['x-bridge-secret' => $this->secret])
            ->timeout(10)
            ->connectTimeout(2)
            ->asJson();
    }
}
