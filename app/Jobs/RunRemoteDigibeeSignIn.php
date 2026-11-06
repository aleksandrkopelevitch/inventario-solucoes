<?php

namespace App\Jobs;

use App\Enums\RemoteSignInStatus;
use App\Models\DigibeeMonitorSession;
use App\Models\User;
use App\Support\Digibee\Monitor\BrowserRuntime;
use App\Support\Digibee\Monitor\RemoteBrowser;
use App\Support\Digibee\Monitor\RemoteSignIn;
use Illuminate\Contracts\Process\InvokedProcess;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Process;
use Illuminate\Support\Sleep;
use Illuminate\Support\Str;
use Throwable;

/**
 * Runs one remote Digibee sign-in: launches `scripts/digibee-remote-sign-in.mjs`
 * (a headless Chromium on the server, opened on the realm's SSO link), tells
 * the screen which port its bridge listens on, and waits — while an admin
 * drives the browser from "Sessão Digibee" — until the browser has seen an
 * authenticated Monitor call. The headers go to `digibee_monitor_sessions`,
 * encrypted; nothing else about the run is kept.
 *
 * A queued job rather than a process spawned from the web request, because
 * the browser outlives every request that looks at it, and a worker is the
 * one long-lived process the deploy already supervises and restarts. It holds
 * one of the two `isol-queue` workers for as long as somebody takes to sign
 * in — minutes, rarely — which is the price of not running a second daemon.
 */
class RunRemoteDigibeeSignIn implements ShouldQueue
{
    use Queueable;

    /**
     * `wait_seconds` (300) for the person, plus the browser's launch and the
     * close — inside the worker's `--timeout=660` and `retry_after` (900), so
     * a second worker never starts the same sign-in on the same profile.
     */
    public int $timeout = 420;

    /** A sign-in is never retried behind the person's back: they press the button again. */
    public int $tries = 1;

    public bool $failOnTimeout = true;

    /** How long Chromium gets to come up before the run is called a failure. */
    private const LAUNCH_SECONDS = 60;

    public function __construct(public readonly string $signInId) {}

    public function handle(): void
    {
        $signIn = RemoteSignIn::current();

        // Cancelled, replaced or already handled while queued.
        if ($signIn === null || $signIn->id !== $this->signInId || $signIn->status !== RemoteSignInStatus::Starting) {
            return;
        }

        $process = $this->launch($signIn);

        try {
            $port = $this->awaitPort($process);

            if ($port === null) {
                Log::warning('Remote Digibee sign-in: the browser did not start.', [
                    'stderr' => Str::limit($process->errorOutput(), 2000),
                ]);
                $signIn->with(RemoteSignInStatus::Failed, message: 'O navegador não abriu no servidor. Veja o log do worker.');

                return;
            }

            $this->watch($signIn->with(RemoteSignInStatus::Waiting, $port), $process);
        } finally {
            $this->close($process, RemoteSignIn::current());
        }
    }

    public function failed(?Throwable $exception): void
    {
        $signIn = RemoteSignIn::current();

        if ($signIn?->id === $this->signInId && $signIn->status->active()) {
            $signIn->with(RemoteSignInStatus::Failed, message: 'O login no servidor parou com um erro. Tente de novo.');
        }
    }

    private function launch(RemoteSignIn $signIn): InvokedProcess
    {
        $config = config('services.digibee.monitor');
        $remote = $config['remote'];

        if (! is_dir($remote['home_path'])) {
            mkdir($remote['home_path'], 0700, true);
        }

        return Process::path(base_path())
            ->timeout($remote['wait_seconds'] + 120)
            ->env(array_filter([
                // In the environment, not argv: argv is world-readable via `ps`.
                'DIGIBEE_BRIDGE_SECRET' => $signIn->secret,
                // www-data's home is not writable on the droplet, and Chromium
                // writes crash and config files under it.
                'HOME' => $remote['home_path'],
                // Named explicitly even when unset, because the default
                // Playwright would derive lives under the HOME just replaced.
                'PLAYWRIGHT_BROWSERS_PATH' => $remote['browsers_path'] ?: rtrim((string) getenv('HOME'), '/') . '/.cache/ms-playwright',
                'LD_LIBRARY_PATH'          => BrowserRuntime::libraryPath(),
            ]))
            ->start([
                $remote['node_binary'], base_path('scripts/digibee-remote-sign-in.mjs'),
                '--playwright=' . BrowserRuntime::playwrightPath(),
                '--profile=' . $remote['profile_path'],
                '--endpoint=' . $config['endpoint'],
                '--app-url=' . $config['app_url'],
                '--login-url=' . $config['login_url'],
                '--monitor-url=' . rtrim($config['app_url'], '/') . '/' . $config['realm'] . '/operation/monitor/prod/execution-finish',
                '--timeout=' . $remote['wait_seconds'],
            ]);
    }

    /** The bridge's first stdout line is `{"port": N}`. */
    private function awaitPort(InvokedProcess $process): ?int
    {
        for ($tick = 0; $tick < self::LAUNCH_SECONDS * 4; $tick++) {
            $line = strtok($process->output(), "\n");
            $port = is_string($line) ? (json_decode($line, true)['port'] ?? null) : null;

            if (is_int($port)) {
                return $port;
            }

            if (! $process->running()) {
                return null;
            }

            Sleep::for(250)->milliseconds();
        }

        return null;
    }

    private function watch(RemoteSignIn $signIn, InvokedProcess $process): void
    {
        $browser = $signIn->browser();
        $deadline = now()->addSeconds(config('services.digibee.monitor.remote.wait_seconds') + 30);

        while (true) {
            Sleep::for(1)->second();

            // Cancelled from the screen (or replaced): the finally closes it.
            if (! $signIn->stillCurrent()) {
                return;
            }

            if (! $process->running()) {
                $signIn->with(RemoteSignInStatus::Failed, message: 'O navegador do servidor fechou antes de o login terminar.');

                return;
            }

            $state = $browser->state();

            if (($state['status'] ?? null) === 'captured') {
                $session = $browser->session();

                if ($session === null) {
                    $signIn->with(RemoteSignInStatus::Failed, message: 'O login terminou, mas a sessão não pôde ser lida do navegador.');

                    return;
                }

                DigibeeMonitorSession::capture($session, $signIn->userId ? User::find($signIn->userId) : null);
                $signIn->with(RemoteSignInStatus::Captured);

                return;
            }

            if (($state['status'] ?? null) === 'failed' || now()->isAfter($deadline)) {
                $signIn->with(RemoteSignInStatus::Failed, message: 'O tempo para entrar acabou. Tente de novo.');

                return;
            }
        }
    }

    /** Asks the bridge to close the browser, then makes sure the process is gone. */
    private function close(InvokedProcess $process, ?RemoteSignIn $signIn): void
    {
        if (! $process->running()) {
            return;
        }

        if ($signIn?->id === $this->signInId && $signIn->port !== null) {
            (new RemoteBrowser($signIn->port, $signIn->secret))->quit();

            for ($tick = 0; $tick < 20 && $process->running(); $tick++) {
                Sleep::for(250)->milliseconds();
            }
        }

        if ($process->running()) {
            $process->signal(SIGTERM);
        }
    }
}
