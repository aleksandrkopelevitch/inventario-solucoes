<?php

namespace App\Support\Digibee\Testing;

use InvalidArgumentException;

/**
 * The deliberately small JsonPath subset the pipeline test assertions speak.
 *
 * `find()` returns a NODELIST — zero, one or many matches — which is JsonPath's
 * own semantic and is what lets "does this field exist" and "what is its
 * value" be the same call without a sentinel value for absence. An empty list
 * means the path matched nothing.
 *
 * **An unsupported path is REFUSED, never silently unmatched.** That is the
 * whole reason this is hand-written instead of pattern-matched loosely: the
 * `choice` conditions in a flowSpec are full JsonPath filters
 * (`$.[?(@.status >= 200 && @.status <= 299)]`), so filter syntax will be
 * pasted into an assertion sooner or later — and a filter treated as "matched
 * nothing" turns an `exists` assertion into a silent false and a `missing`
 * assertion into a silent pass. Wrong for the wrong reason is worse than
 * unsupported.
 *
 * Supported: `$`, `.key`, `['key']`, `[0]`, `[*]`. Not supported, on purpose:
 * filters, recursive descent (`..`), slices, functions.
 */
final class JsonPath
{
    /** @return list<mixed> the nodes the path matched, in document order */
    public static function find(mixed $document, string $path): array
    {
        $nodes = [$document];

        foreach (self::segments($path) as [$kind, $value]) {
            $next = [];

            foreach ($nodes as $node) {
                if (! is_array($node)) {
                    continue;
                }

                if ($kind === 'wildcard') {
                    $next = [...$next, ...array_values($node)];
                } elseif (array_key_exists($value, $node)) {
                    $next[] = $node[$value];
                }
            }

            if ($next === []) {
                return [];
            }

            $nodes = $next;
        }

        return $nodes;
    }

    public static function supports(string $path): bool
    {
        try {
            self::segments($path);

            return true;
        } catch (InvalidArgumentException) {
            return false;
        }
    }

    /**
     * @return list<array{0: string, 1: string|int|null}>
     *
     * @throws InvalidArgumentException
     */
    public static function segments(string $path): array
    {
        $path = trim($path);

        if ($path === '') {
            throw new InvalidArgumentException('Empty JsonPath.');
        }

        if ($path === '$') {
            return []; // the whole document
        }

        // A leading `$` is optional and so is the first dot, so `$.a.b`, `.a.b`
        // and `a.b` are one path written three ways — all three turn up in
        // hand-written assertions.
        $rest = str_starts_with($path, '$') ? substr($path, 1) : $path;
        $rest = ($rest === '' || $rest[0] === '.' || $rest[0] === '[') ? $rest : '.' . $rest;

        $segments = [];

        while ($rest !== '') {
            $match = null;

            if (preg_match('/^\.([A-Za-z_][A-Za-z0-9_-]*)/', $rest, $match) === 1) {
                $segments[] = ['key', $match[1]];
            } elseif (preg_match('/^\[(\d+)\]/', $rest, $match) === 1) {
                $segments[] = ['index', (int) $match[1]];
            } elseif (preg_match('/^\[\*\]/', $rest, $match) === 1) {
                $segments[] = ['wildcard', null];
            } elseif (preg_match('/^\[\'([^\']*)\'\]|^\["([^"]*)"\]/', $rest, $match) === 1) {
                $segments[] = ['key', $match[2] ?? $match[1]];
            } else {
                throw new InvalidArgumentException(
                    "Unsupported JsonPath syntax at \"{$rest}\" — filters, recursive descent and slices are not supported."
                );
            }

            $rest = substr($rest, strlen($match[0]));
        }

        return $segments;
    }
}
