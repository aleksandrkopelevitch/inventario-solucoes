<?php

namespace App\Support\Digibee\Monitor;

/**
 * Masks what a production log line must not carry into a terminal or a model
 * prompt.
 *
 * Measured, not hypothetical: on 2026-11-04 the Monitor showed three pipelines
 * (zfl-cli-analise-fin-detalhe, zfl-valida-credito-clie,
 * zfl-cliente-seleciona-penca) logging their whole inbound request — the
 * consumer's `Authorization: Basic` included — and evt-pedido-vtex-sap logging
 * full VTEX orders with the customer's CPF, e-mail and phone. Whatever reads
 * these logs on a person's behalf inherits both.
 *
 * Over-redaction is the cheap side of the trade. A masked phone costs a
 * troubleshooting session nothing; an unmasked Basic credential in a chat
 * transcript costs a rotation. What this deliberately leaves alone is NAMES:
 * product names, pipeline names and SAP fields share the shape of a person's
 * name, and masking them would gut the logs this exists to read.
 */
final class LogRedactor
{
    /** JSON keys whose string value is a secret whatever it looks like. */
    private const SECRET_KEYS = 'authorization|password|passwd|senha|secret|client_secret|token|access_token|refresh_token|apikey|api_key|x-api-key';

    /**
     * JSON keys whose value identifies a person — matched anywhere in the key,
     * because the payloads name them freely: a real `"CpfCnpj":"…"` from
     * evt-insert-bq went through when only the bare `cpf`/`cnpj` were listed.
     */
    private const PERSONAL_KEYS = '[\w-]*(?:document|cpf|cnpj|email|e-mail|phone|telefone|celular|mobile)[\w-]*';

    public function redact(string $text): string
    {
        $rules = [
            // "Authorization":"Basic …", "token":"…" — the key decides, so an
            // opaque value of any shape is caught.
            //
            // The quotes may be escaped: execution payloads are JSON held in a
            // JSON string, so a key reads `\"document\":\"…\"`. Missing that
            // let a real CNPJ and phone through on 2026-11-04.
            '/(\\\\?"(?:' . self::SECRET_KEYS . ')\\\\?"\s*:\s*\\\\?")[^"\\\\]+(\\\\?")/i'   => '$1[redacted]$2',
            '/(\\\\?"(?:' . self::PERSONAL_KEYS . ')\\\\?"\s*:\s*\\\\?")[^"\\\\]+(\\\\?")/i' => '$1[redacted]$2',
            '/(\\\\?"(?:' . self::PERSONAL_KEYS . ')\\\\?"\s*:\s*)\d{6,}/i'                  => '$1[redacted]',
            '/(<(' . self::SECRET_KEYS . ')>)[^<]+(<\/\2>)/i'                                => '$1[redacted]$3',
            // Free text: a scheme followed by a credential, and bare JWTs.
            '/\b(Basic|Bearer)\s+[A-Za-z0-9._~+\/=-]{8,}/'                     => '$1 [redacted]',
            '/\beyJ[A-Za-z0-9_-]{10,}\.[A-Za-z0-9_-]{10,}\.[A-Za-z0-9_-]{5,}/' => '[redacted-jwt]',
            // Possessive and anchored to the START of a run, so a long blob
            // with no `@` is scanned once per run. An earlier note blamed the
            // unanchored form for a 200-execution listing that took minutes;
            // that was never measured against it (the retry failed on DNS),
            // and PCRE2's start-of-match optimizations keep that form linear
            // too — it only goes quadratic under (*NO_START_OPT). The growth
            // test in DigibeeMonitorLogsTest is what holds this now.
            '/(?<![A-Za-z0-9._%+-])[A-Za-z0-9._%+-]++@[A-Za-z0-9.-]+\.[A-Za-z]{2,}/' => '[email]',
            '/\b\d{3}\.\d{3}\.\d{3}-\d{2}\b/'                                        => '[cpf]',
            '/\b\d{2}\.\d{3}\.\d{3}\/\d{4}-\d{2}\b/'                                 => '[cnpj]',
            '/\+55\s?\(?\d{2}\)?\s?\d{4,5}-?\d{4}\b/'                                => '[phone]',
        ];

        return (string) preg_replace(array_keys($rules), array_values($rules), $text);
    }

    /**
     * Any structure as redacted JSON — twice on purpose. Each string first, at
     * its own level of escaping: a payload is JSON in a string, and its body
     * is often JSON in a string again, which encoding once more would push one
     * escape deeper than the key rules reach. Then the encoded whole, for
     * anything the structure itself carries.
     */
    public function json(mixed $data, int $flags = JSON_PRETTY_PRINT): string
    {
        if (is_array($data)) {
            array_walk_recursive($data, function (mixed &$value) {
                if (is_string($value)) {
                    $value = $this->redact($value);
                }
            });
        } elseif (is_string($data)) {
            $data = $this->redact($data);
        }

        return $this->redact((string) json_encode($data, $flags | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE));
    }
}
