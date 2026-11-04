<?php

namespace App\Console\Commands;

use App\Exceptions\DigibeeApiException;
use App\Support\Digibee\Monitor\MonitorSession;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Process;

/**
 * Captures the Digibee web session `digibee:logs` reads with.
 *
 * Opens a browser window on the Monitor screen with a persistent profile. A
 * person signs in there through the IdP (no Digibee password exists for this
 * realm, and nothing here would type one); the screen then calls the API and
 * `scripts/digibee-monitor-login.mjs` keeps the headers of that call. While the
 * IdP's own session lives, a later run signs in without a click.
 *
 * Workstation only, like `digibee:pipelines:pull`: what it captures is the
 * person's whole role.
 */
class DigibeeMonitorLoginCommand extends Command
{
    protected $signature = 'digibee:monitor:login';

    protected $description = 'Open the Digibee Monitor in a browser window and capture the session digibee:logs reads with';

    public function handle(): int
    {
        if (app()->isProduction()) {
            $this->components->error(DigibeeApiException::refusedMonitorOnServer()->getMessage());

            return self::FAILURE;
        }

        $playwright = $this->playwrightPath();

        if ($playwright === null) {
            $this->components->error('Playwright not found. Set DIGIBEE_MONITOR_PLAYWRIGHT to its package directory (…/node_modules/playwright).');

            return self::FAILURE;
        }

        $config = config('services.digibee.monitor');
        $wait = (int) $config['login_wait_seconds'];

        $result = Process::timeout($wait + 60)
            ->env(array_filter(['LD_LIBRARY_PATH' => $this->libraryPath()]))
            ->run([
                'node', base_path('scripts/digibee-monitor-login.mjs'),
                '--playwright=' . $playwright,
                '--profile=' . $config['profile_path'],
                '--out=' . $config['session_path'],
                '--endpoint=' . $config['endpoint'],
                '--url=' . rtrim($config['app_url'], '/') . '/' . $config['realm'] . '/operation/monitor/prod/execution-finish',
                '--login-url=' . $config['login_url'],
                '--timeout=' . $wait,
            ], fn (string $type, string $output) => $this->output->write($output));

        if ($result->failed()) {
            $this->components->error('No session captured (exit ' . $result->exitCode() . ').');

            return self::FAILURE;
        }

        $expiresAt = MonitorSession::fromFile($config['session_path'])?->expiresAt();
        $this->components->info('Session saved' . ($expiresAt ? ', valid until ' . $expiresAt->setTimezone(config('app.display_timezone'))->format('d/m H:i') : '') . '.');

        return self::SUCCESS;
    }

    /** Configured, else the npx cache — where Playwright lives on this machine, outside the project. */
    private function playwrightPath(): ?string
    {
        $configured = (string) config('services.digibee.monitor.playwright_path');

        if ($configured !== '') {
            return is_dir($configured) ? $configured : null;
        }

        $found = glob(rtrim((string) getenv('HOME'), '/') . '/.npm/_npx/*/node_modules/playwright', GLOB_ONLYDIR) ?: [];

        return $found[0] ?? null;
    }

    /** WSL ships without libasound, which Chromium needs even to open a window. */
    private function libraryPath(): ?string
    {
        $configured = (string) config('services.digibee.monitor.ld_library_path');
        $fallback = rtrim((string) getenv('HOME'), '/') . '/.local/share/digibee-monitor/libs/usr/lib/x86_64-linux-gnu';
        $path = $configured !== '' ? $configured : (is_dir($fallback) ? $fallback : null);

        return $path === null ? null : implode(':', array_filter([$path, getenv('LD_LIBRARY_PATH') ?: null]));
    }
}
