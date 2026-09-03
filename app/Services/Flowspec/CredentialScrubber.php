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
    /**
     * Suffixes that turn a sensitive-sounding key into a DESCRIPTION of a
     * credential rather than the credential.
     *
     * `lastPasswordUpdate` is a date, `authorizationDate` is a date,
     * `tokenUrl` is an endpoint — and all three were reported as literal
     * secrets, because the fragment match below only asks whether the word
     * appears anywhere in the name. Found auditing the real export
     * (2026-09-02), where it also meant the flowSpec VALIDATOR would reject a
     * generated pipeline carrying a legitimate `authorizationDate` mapping and
     * FlowspecGenerationService would then discard the document outright.
     */
    private const DESCRIPTIVE_KEY_SUFFIXES = [
        'date', 'at', 'time', 'update', 'updated', 'expires', 'expiry', 'expiration',
        'id', 'name', 'type', 'url', 'uri', 'count', 'length', 'flag', 'enabled',
    ];

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

        foreach (self::DESCRIPTIVE_KEY_SUFFIXES as $suffix) {
            // Checked before the fragments, and only when something precedes
            // it: a key called exactly `id` or `name` is not sensitive either
            // way, but `password` must not be excused by ending in a word that
            // happens to be a suffix of itself.
            if ($normalized !== $suffix && str_ends_with($normalized, $suffix)) {
                return false;
            }
        }

        foreach (self::SENSITIVE_KEY_FRAGMENTS as $fragment) {
            if (str_contains($normalized, $fragment)) {
                return true;
            }
        }

        return false;
    }

    /**
     * Whether the value under a sensitive key is an actual VALUE.
     *
     * Four things are not, and each cost a false positive on the real export:
     * a Double Braces reference (the whole point of the rule), a JOLT
     * operation (`=split('...')`), a bare field PATH — a transformSpec's values
     * are the names of fields being mapped, `payment.authorizationToken`, not
     * anything secret — and a date.
     *
     * A path is only excused when it has no whitespace AND contains a `.` or
     * `[`, so a 32-character API key with no punctuation is still a literal.
     * A JWT reaches here as a path-shaped string too, but the JWT pattern in
     * `walk()` is checked independently of the key, so it is still caught.
     */
    private function isLiteral(string $value): bool
    {
        $value = trim($value);

        if ($value === '' || str_contains($value, '{{') || str_starts_with($value, '=')) {
            return false;
        }

        if (preg_match('/^\d{4}-\d{2}-\d{2}([T ]|$)/', $value) === 1) {
            return false;
        }

        return preg_match('/^[A-Za-z_$@][A-Za-z0-9_$@.\[\]*-]*[.\[][A-Za-z0-9_$@.\[\]*-]*$/', $value) !== 1;
    }
}
