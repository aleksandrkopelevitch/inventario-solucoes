<?php

namespace App\Support\Digibee;

/**
 * What survives from a real pipeline's params into the committed vocabulary.
 *
 * The whole point of the tenant corpus is the part Digibee's own docs cannot
 * give: the real JSON keys (the docs print "Stop On Client Error", the flowSpec
 * says `stopOnClientError`), the shape of each value, and how we actually write
 * Double Braces. None of that needs a live endpoint in it.
 *
 * So the rule is: **names and expressions are vocabulary; addresses are not.**
 *
 * - **Double Braces expressions survive verbatim.** `{{ global.url-base-viasoft }}`
 *   and an `accountLabel` are NAMES, not values — that was the explicit call —
 *   and they are the single most instructive thing in the corpus: they teach
 *   the model the vocabulary it would otherwise invent, and an invented
 *   `global.*` validates clean and dies at runtime.
 * - **Enum-ish scalars survive**: booleans, numbers and short bare words
 *   (`POST`, `UPDATE`, `UTF-8`). These are the allowed-value sets a card lists
 *   in prose, spelled the way the JSON spells them.
 * - **Anything that addresses a machine is replaced by its TYPE** — URLs,
 *   hostnames, IPs, paths, e-mails, and any long free string. Our export
 *   contains literals like `10.158.1.37`, and a prompt is the wrong place for
 *   the internal network's shape.
 * - **Anything CredentialScrubber would flag never gets here**: the indexer
 *   runs it over the whole document first.
 */
class ParamRedactor
{
    private const MAX_KEPT_CHARS = 48;

    /** A bare word or short enum value — no dots, slashes, colons or spaces-as-sentence. */
    private const ENUM_LIKE = '/^[A-Za-z0-9][A-Za-z0-9 _\-]{0,46}$/u';

    /** Looks like it addresses something: a scheme, a host, a path, an IP, an e-mail. */
    private const ADDRESSY = '~(://|@|^/|^[A-Za-z0-9.-]+\.[A-Za-z]{2,}$|^\d{1,3}(\.\d{1,3}){3}$)~';

    public function value(mixed $value): mixed
    {
        if (is_bool($value) || is_int($value) || is_float($value) || $value === null) {
            return $value;
        }

        if (is_array($value)) {
            return $this->arrayValue($value);
        }

        if (! is_string($value)) {
            return '<' . gettype($value) . '>';
        }

        return $this->string($value);
    }

    /** @param array<mixed> $value */
    private function arrayValue(array $value): array
    {
        $redacted = [];

        foreach ($value as $key => $child) {
            $redacted[$key] = $this->value($child);
        }

        return $redacted;
    }

    /**
     * Replaces `scheme://host` — and a bare host at the start of a path — with
     * `<endpoint>`, leaving everything else intact.
     */
    private function withoutHosts(string $value): string
    {
        $value = (string) preg_replace('~https?://[^/\s"\']+~i', '<endpoint>', $value);

        return (string) preg_replace('~^[A-Za-z0-9][A-Za-z0-9.-]*\.[A-Za-z]{2,}(?=[/:])~', '<endpoint>', $value);
    }

    private function string(string $value): string
    {
        $trimmed = trim($value);

        if ($trimmed === '') {
            return '';
        }

        // An expression is kept — `{{ global.url-base }}` is the NAME of the
        // place an address is configured, and composing a URL out of one is
        // exactly the lesson — but the expression does not launder a literal
        // address sitting beside it. Found on the first real pull:
        // `https://leomadeiras.freshservice.com/api/v2/attachments/{{ message.id }}`
        // survived whole, because "contains {{" was checked first and answered
        // for the whole string. The scheme and host go; the path and the
        // expression stay, so the composition is still legible.
        if (str_contains($trimmed, '{{')) {
            $trimmed = $this->withoutHosts($trimmed);

            return mb_strlen($trimmed) <= 200 ? $trimmed : '<double-braces>';
        }

        // A serialized JSON param (`headers` is often one) is walked rather
        // than dropped: its KEYS are the vocabulary, and its values go through
        // the same rules.
        if (str_starts_with($trimmed, '{') || str_starts_with($trimmed, '[')) {
            $decoded = json_decode($trimmed, true);

            if (is_array($decoded)) {
                return (string) json_encode($this->arrayValue($decoded), JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
            }
        }

        if (preg_match(self::ADDRESSY, $trimmed) === 1) {
            return '<endpoint>';
        }

        if (mb_strlen($trimmed) > self::MAX_KEPT_CHARS || preg_match(self::ENUM_LIKE, $trimmed) !== 1) {
            return '<string>';
        }

        return $trimmed;
    }
}
