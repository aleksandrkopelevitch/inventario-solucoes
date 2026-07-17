<?php

namespace App\Support;

use BladeUI\Icons\Exceptions\SvgNotFound;
use BladeUI\Icons\Factory;
use Illuminate\Support\Facades\Cache;

/**
 * Thin bridge to `blade-ui-kit/blade-heroicons` outside a Blade context —
 * used to embed the (outline) SVG of a chosen icon inside payloads consumed
 * by JS (e.g. `data-integration-graph`, read by `integration-viz.js`; the
 * documentation callouts' icon picker), where `<x-heroicon-o-*>` doesn't apply.
 */
class Heroicons
{
    /** Rendered (outline) SVG, or null if `$name` is empty or doesn't exist. */
    public static function outlineSvg(?string $name, string $class = ''): ?string
    {
        if (! $name) {
            return null;
        }

        try {
            return app(Factory::class)->svg("heroicon-o-{$name}", $class)->toHtml();
        } catch (SvgNotFound) {
            return null;
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
