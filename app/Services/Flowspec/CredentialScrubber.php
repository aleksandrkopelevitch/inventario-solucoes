<?php

namespace App\Services\Flowspec;

/**
 * Detects literal secrets (API key, password, token) in a flowSpec. Sensitive
 * values may only enter via a Double Braces reference ({{ account.* }},
 * {{ global.* }}) — never as a literal. Runs as a guard in the corpus seeder
 * and as a DigibeeFlowspecValidator rule over what the AI generates.
 */
class CredentialScrubber
{
    /** Fragments (lowercase key, no -_/space) that mark a key as sensitive. */
    private const SENSITIVE_KEY_FRAGMENTS = [
        'password', 'senha', 'secret', 'apikey', 'authorization',
        'credential', 'accesstoken', 'refreshtoken', 'privatekey',
    ];

    /** Literal-secret patterns inside any string value. */
    private const VALUE_PATTERNS = [
        'JWT literal'                            => '/\beyJ[A-Za-z0-9_-]{20,}\.[A-Za-z0-9_-]{10,}/',
        'Bearer token literal'                   => '/\bBearer\s+(?!\{\{)[A-Za-z0-9._~+\/=-]{20,}/',
        'campo sensível com literal em template' => '/"(?:client_secret|password|senha|x-api-key|api[-_]?key|access_token|refresh_token)"\s*:\s*"(?!\s*\{\{)[^"{}]{4,}"/i',
    ];

    /**
     * @param  array<string, mixed>  $document
     * @return list<string> descriptions of the leaks found (empty = clean)
     */
    public function violations(array $document): array
    {
        $violations = [];

        $this->walk($document, '$', $violations);

        return array_values(array_unique($violations));
    }

    /** @param list<string> $violations */
    private function walk(mixed $value, string $path, array &$violations): void
    {
        if (is_array($value)) {
            foreach ($value as $key => $child) {
                if (is_string($key) && is_string($child) && $this->isSensitiveKey($key) && $this->isLiteral($child)) {
                    $violations[] = "{$path}.{$key}: chave sensível com valor literal (use {{ account.* }}/{{ global.* }})";
                }

                $this->walk($child, "{$path}.{$key}", $violations);
            }

            return;
        }

        if (! is_string($value) || $value === '') {
            return;
        }

        // Params like `headers` carry serialized JSON as a string — scan it too.
        if (str_starts_with(ltrim($value), '{')) {
            $decoded = json_decode($value, true);

            if (is_array($decoded)) {
                $this->walk($decoded, "{$path}(json)", $violations);
            }
        }

        foreach (self::VALUE_PATTERNS as $label => $pattern) {
            if (preg_match($pattern, $value)) {
                $violations[] = "{$path}: {$label}";
            }
        }
    }

    private function isSensitiveKey(string $key): bool
    {
        $normalized = str_replace(['-', '_', ' '], '', mb_strtolower($key));

        if ($normalized === 'token') {
            return true;
        }

        foreach (self::SENSITIVE_KEY_FRAGMENTS as $fragment) {
            if (str_contains($normalized, $fragment)) {
                return true;
            }
        }

        return false;
    }

    /** A value with any Double Braces reference is not a literal secret. */
    private function isLiteral(string $value): bool
    {
        return trim($value) !== '' && ! str_contains($value, '{{');
    }
}
