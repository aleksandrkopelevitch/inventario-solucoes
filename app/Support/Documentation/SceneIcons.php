<?php

namespace App\Support\Documentation;

/**
 * The icons a scene may show beside a step, a change or a tree item — a closed
 * catalogue, like everything else a scene's model is allowed to choose.
 *
 * ONE file is the vocabulary for both ends: `resources/data/scene-icons.json`
 * (key → PT-BR label + the Heroicons outline paths). This class reads it to
 * validate what the model or the page names and to list the choices in the
 * prompt; docs-scene.js imports the same file to draw them and the editor's
 * picker to offer them. Two lists would drift the first time somebody added an
 * icon to one of them.
 *
 * The paths are Heroicons v2 outline (MIT) — the set the app already draws its
 * own interface with (`x-heroicon-o-*`), so an icon inside a figure looks like
 * every other icon on the screen.
 *
 * An icon is decoration, never content: a name outside the catalogue is
 * DROPPED (`sanitize()`), not refused. Failing a whole scene — or spending a
 * repair round — over a picture would be the wrong trade.
 */
final class SceneIcons
{
    /** @var array<string, array{label: string, heroicon: string, paths: list<string>}>|null */
    private static ?array $icons = null;

    /** @return array<string, array{label: string, heroicon: string, paths: list<string>}> */
    public static function all(): array
    {
        // A plain file read, held for the process: there is no database or
        // cache behind it to go stale, only the file the deploy shipped.
        return self::$icons ??= json_decode(
            (string) file_get_contents(resource_path('data/scene-icons.json')),
            true,
            512,
            JSON_THROW_ON_ERROR,
        )['icons'];
    }

    public static function has(mixed $key): bool
    {
        return is_string($key) && array_key_exists($key, self::all());
    }

    /** The key if it is one of the catalogue's, '' otherwise. */
    public static function sanitize(mixed $key): string
    {
        $key = is_string($key) ? trim($key) : '';

        return self::has($key) ? $key : '';
    }

    /** "documento (Documento), pessoa (Pessoa), …" — the list the prompt hands the model. */
    public static function promptList(): string
    {
        return collect(self::all())
            ->map(fn (array $icon, string $key) => "{$key} ({$icon['label']})")
            ->implode(', ');
    }
}
