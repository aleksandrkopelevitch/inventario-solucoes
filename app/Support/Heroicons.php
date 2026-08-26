<?php

namespace App\Support;

use BladeUI\Icons\Exceptions\SvgNotFound;
use BladeUI\Icons\Factory;
use Illuminate\Support\Facades\Cache;

/**
 * Thin bridge to `blade-ui-kit/blade-heroicons` outside a Blade context —
 * used to embed the (outline) SVG of a chosen icon inside payloads consumed
 * by JS (e.g. `data-ak-chain-graph`, read by `chain-viz.js`; the
 * documentation callouts' icon picker), where `<x-heroicon-o-*>` doesn't apply.
 */
class Heroicons
{
    /**
     * Per-request memo of rendered SVGs, keyed by name+class. `Factory::svg()`
     * reads the file and rebuilds the markup on every call, and the callers here
     * ask for the SAME handful of icons once per graph node: a solution's
     * environment/cloud badges plus the block kind's glyph, for every node of
     * every diagram on the page. Values include null (icon doesn't exist),
     * so misses are memoized too.
     *
     * @var array<string, string|null>
     */
    private static array $memo = [];

    /** Rendered (outline) SVG, or null if `$name` is empty or doesn't exist. */
    public static function outlineSvg(?string $name, string $class = ''): ?string
    {
        if (! $name) {
            return null;
        }

        $key = $name . '|' . $class;

        if (array_key_exists($key, self::$memo)) {
            return self::$memo[$key];
        }

        try {
            return self::$memo[$key] = app(Factory::class)->svg("heroicon-o-{$name}", $class)->toHtml();
        } catch (SvgNotFound) {
            return self::$memo[$key] = null;
        }
    }

    public static function exists(?string $name): bool
    {
        return self::outlineSvg($name) !== null;
    }

    /**
     * All outline icons in the set, as `[{name, svg}]` — source for the
     * callouts' icon picker. The set is static (comes from the package), so
     * it's cached permanently; the version in the cache key name allows
     * invalidating it on an upgrade.
     *
     * @return array<int, array{name: string, svg: string}>
     */
    public static function allOutline(): array
    {
        return Cache::rememberForever('heroicons.outline.v1', function (): array {
            $dir = base_path('vendor/blade-ui-kit/blade-heroicons/resources/svg');

            return collect(glob($dir . '/o-*.svg') ?: [])
                ->map(fn (string $path): string => substr(basename($path, '.svg'), 2)) // strips the "o-" prefix
                ->sort()
                ->map(fn (string $name): array => ['name' => $name, 'svg' => self::outlineSvg($name)])
                ->filter(fn (array $icon): bool => $icon['svg'] !== null)
                ->values()
                ->all();
        });
    }
}
