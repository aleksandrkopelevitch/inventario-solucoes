<?php

namespace App\Mcp\Support;

use App\Mcp\InvalidToolArguments;

/**
 * Reads a `tools/call` argument bag.
 *
 * Deliberately not a Form Request. Those exist for the app's own HTTP surface
 * and answer in the app's own JSON shape (`{message, title, type}`, § Blade —
 * ValidationException); an MCP client does not read that shape and the error has
 * to reach the model as a JSON-RPC `INVALID_PARAMS`, which is what
 * `InvalidToolArguments` is for. What is left after that is four accessors.
 *
 * A model calling a tool gets its arguments slightly wrong all the time — a
 * number as `"10"`, a null where it means "not set" — so every accessor here
 * coerces rather than refusing. Only a genuinely missing required value throws.
 */
final class Arguments
{
    /** @param array<string, mixed> $values */
    public function __construct(private readonly array $values) {}

    public function string(string $key, ?string $default = null): ?string
    {
        $value = $this->values[$key] ?? null;

        if ($value === null || $value === '') {
            return $default;
        }

        return is_scalar($value) ? trim((string) $value) : $default;
    }

    public function required(string $key): string
    {
        return $this->string($key)
            ?? throw new InvalidToolArguments("O argumento \"{$key}\" é obrigatório.");
    }

    /**
     * A bounded integer.
     *
     * Every limit in this file is clamped rather than validated, because the
     * ceiling exists to protect the response and not to correct the model: a
     * tool asked for 5000 results should answer with the most it can sanely
     * return, not refuse and make the model guess a number.
     */
    public function int(string $key, int $default, int $min, int $max): int
    {
        $value = $this->values[$key] ?? null;

        if (! is_numeric($value)) {
            return $default;
        }

        return max($min, min($max, (int) $value));
    }

    public function bool(string $key, bool $default = false): bool
    {
        $value = $this->values[$key] ?? null;

        return is_bool($value) ? $value : filter_var($value, FILTER_VALIDATE_BOOL, FILTER_NULL_ON_FAILURE) ?? $default;
    }

    /** @return list<string> */
    public function list(string $key): array
    {
        $value = $this->values[$key] ?? null;

        if (! is_array($value)) {
            return [];
        }

        return array_values(array_filter(array_map(
            fn ($item) => is_scalar($item) ? trim((string) $item) : '',
            $value,
        ), fn (string $item) => $item !== ''));
    }
}
