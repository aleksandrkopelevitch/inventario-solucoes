<?php

namespace App\Services\Flowspec;

/**
 * Detecta segredo literal (chave de API, senha, token) num flowSpec. Valores
 * sensíveis só podem entrar por referência Double Braces ({{ account.* }},
 * {{ global.* }}) — nunca como literal. Roda como guarda no seeder do corpus
 * e como regra do DigibeeFlowspecValidator sobre o que a IA gerar.
 */
class CredentialScrubber
{
    /** Fragmentos (chave minúscula, sem -_/espaço) que marcam uma chave como sensível. */
    private const SENSITIVE_KEY_FRAGMENTS = [
        'password', 'senha', 'secret', 'apikey', 'authorization',
        'credential', 'accesstoken', 'refreshtoken', 'privatekey',
    ];

    /** Padrões de segredo literal dentro de qualquer valor string. */
    private const VALUE_PATTERNS = [
        'JWT literal'                            => '/\beyJ[A-Za-z0-9_-]{20,}\.[A-Za-z0-9_-]{10,}/',
        'Bearer token literal'                   => '/\bBearer\s+(?!\{\{)[A-Za-z0-9._~+\/=-]{20,}/',
        'campo sensível com literal em template' => '/"(?:client_secret|password|senha|x-api-key|api[-_]?key|access_token|refresh_token)"\s*:\s*"(?!\s*\{\{)[^"{}]{4,}"/i',
    ];

    /**
     * @param  array<string, mixed>  $document
     * @return list<string> descrições dos vazamentos encontrados (vazio = limpo)
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

        // params como `headers` carregam JSON serializado em string — varre também.
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

    /** Um valor com qualquer referência Double Braces não é segredo literal. */
    private function isLiteral(string $value): bool
    {
        return trim($value) !== '' && ! str_contains($value, '{{');
    }
}
