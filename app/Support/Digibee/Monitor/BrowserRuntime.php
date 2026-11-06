<?php

namespace App\Support\Digibee\Monitor;

/**
 * Where the pieces a Digibee sign-in browser needs live on THIS machine — the
 * workstation login (`digibee:monitor:login`) asks.
 */
final class BrowserRuntime
{
    /** The Playwright package to import: configured, else the npx cache. */
    public static function playwrightPath(): ?string
    {
        $configured = (string) config('services.digibee.monitor.playwright_path');

        if ($configured !== '') {
            return is_dir($configured) ? $configured : null;
        }

        $found = glob(rtrim((string) getenv('HOME'), '/') . '/.npm/_npx/*/node_modules/playwright', GLOB_ONLYDIR) ?: [];

        return $found[0] ?? null;
    }

    /** WSL ships without libasound, which Chromium needs even to open a window. */
    public static function libraryPath(): ?string
    {
        $configured = (string) config('services.digibee.monitor.ld_library_path');
        $fallback = rtrim((string) getenv('HOME'), '/') . '/.local/share/digibee-monitor/libs/usr/lib/x86_64-linux-gnu';
        $path = $configured !== '' ? $configured : (is_dir($fallback) ? $fallback : null);

        return $path === null ? null : implode(':', array_filter([$path, getenv('LD_LIBRARY_PATH') ?: null]));
    }
}
