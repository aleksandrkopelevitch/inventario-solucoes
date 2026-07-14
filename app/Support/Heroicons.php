<?php

namespace App\Support;

use BladeUI\Icons\Exceptions\SvgNotFound;
use BladeUI\Icons\Factory;
use Illuminate\Support\Facades\Cache;

/**
 * Ponte fina para o `blade-ui-kit/blade-heroicons` fora de um contexto Blade
 * — usada para embutir o SVG (outline) de um ícone escolhido dentro de
 * payloads consumidos por JS (ex.: `data-integration-graph`, lido por
 * `integration-viz.js`; o picker de ícones dos callouts da documentação),
 * onde `<x-heroicon-o-*>` não se aplica.
 */
class Heroicons
{
    /** SVG (outline) renderizado, ou null se `$name` estiver vazio ou não existir. */
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
     * Todos os ícones outline do set, como `[{name, svg}]` — fonte do picker de
     * ícones dos callouts. O conjunto é estático (vem do pacote), então fica em
     * cache permanente; a versão no nome da chave permite invalidar num upgrade.
     *
     * @return array<int, array{name: string, svg: string}>
     */
    public static function allOutline(): array
    {
        return Cache::rememberForever('heroicons.outline.v1', function (): array {
            $dir = base_path('vendor/blade-ui-kit/blade-heroicons/resources/svg');

            return collect(glob($dir . '/o-*.svg') ?: [])
                ->map(fn (string $path): string => substr(basename($path, '.svg'), 2)) // tira o prefixo "o-"
                ->sort()
                ->map(fn (string $name): array => ['name' => $name, 'svg' => self::outlineSvg($name)])
                ->filter(fn (array $icon): bool => $icon['svg'] !== null)
                ->values()
                ->all();
        });
    }
}
