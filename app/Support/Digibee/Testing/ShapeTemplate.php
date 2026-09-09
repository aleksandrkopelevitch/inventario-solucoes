<?php

namespace App\Support\Digibee\Testing;

/**
 * The literal keys a `json-generator` or `jslt` step names in its own
 * template — the one place a flowSpec says what its output looks like.
 *
 * A template is deliberately NOT parsed as JSON, because it is not JSON: the
 * values are Double Braces placeholders (`"PARTNER_ID": {{ message.body.id }}`)
 * and a JSLT expression can open with `let` bindings before the object. What
 * is genuinely literal is the KEYS, so this walks the braces and collects the
 * names at depth 1, skipping `{{ … }}` so a placeholder never opens a level.
 */
final readonly class ShapeTemplate
{
    private function __construct(public string $template) {}

    /**
     * The template a step declares, or null when the step declares none —
     * which is most of them: a REST call, a log or an Object Store says
     * nothing about the shape of what comes out.
     *
     * @param  array<string, mixed>  $step
     */
    public static function of(array $step): ?self
    {
        $params = is_array($step['params'] ?? null) ? $step['params'] : [];

        $template = match ($step['name'] ?? null) {
            'json-generator-connector' => $params['json'] ?? null,
            'jslt-connector'           => $params['jsltExpr'] ?? null,
            default                    => null,
        };

        return is_string($template) && trim($template) !== '' ? new self($template) : null;
    }

    /** @return list<string> the keys of the object this template emits */
    public function keys(): array
    {
        return $this->walk()['keys'];
    }

    /**
     * The raw text of one key's value, so a caller can ask whether it is a
     * literal (`"code": 200`) or an expression (`"code": {{ … }}`).
     */
    public function value(string $key): ?string
    {
        return $this->walk()['values'][$key] ?? null;
    }

    /**
     * The trigger's response ENVELOPE, which is not the response.
     *
     * Digibee's HTTP trigger reference is explicit about the three keys:
     * `code` is the HTTP status the endpoint returns, `body` is the response
     * body (and must be a string), `Content-Type` is its content type. So a
     * caller never sees these names — asserting `$.code` on the response would
     * fail against the single most common terminal shape in the tenant (105 of
     * the 178 that declare one).
     */
    public function isResponseEnvelope(): bool
    {
        $keys = array_map(strtolower(...), $this->keys());

        return in_array('body', $keys, true)
            && array_diff($keys, ['body', 'code', 'content-type', 'headers']) === [];
    }

    /** The literal HTTP status this envelope returns, when it is literal at all. */
    public function envelopeStatus(): ?int
    {
        $code = trim((string) ($this->value('code') ?? $this->value('Code') ?? ''));

        return preg_match('/^\d{3}$/', $code) === 1 ? (int) $code : null;
    }

    /**
     * The keys INSIDE the envelope's `body`, when it holds a literal object
     * rather than the `{{ TOSTRING(…) }}` it usually holds.
     *
     * @return list<string>
     */
    public function envelopeBodyKeys(): array
    {
        $body = trim((string) ($this->value('body') ?? ''));

        return str_starts_with($body, '{') && ! str_starts_with($body, '{{')
            ? (new self($body))->keys()
            : [];
    }

    /**
     * One pass over the template: depth-1 key names, in order, with the raw
     * text of each value.
     *
     * @return array{keys: list<string>, values: array<string, string>}
     */
    private function walk(): array
    {
        $keys = [];
        $values = [];
        $depth = 0;
        $started = false;
        $length = strlen($this->template);

        for ($i = 0; $i < $length; $i++) {
            $char = $this->template[$i];

            if ($char === '{') {
                // A `{{ … }}` placeholder is a VALUE, not a nesting level.
                // Counting it would push every following key to depth 2 and
                // the template would read as having no keys at all.
                if (substr($this->template, $i, 2) === '{{') {
                    $close = strpos($this->template, '}}', $i);
                    $i = $close === false ? $length : $close + 1;

                    continue;
                }

                $depth++;
                $started = true;

                continue;
            }

            if ($char === '}') {
                $depth--;

                if ($started && $depth === 0) {
                    break; // the emitted object is closed; `let` tails are not ours
                }

                continue;
            }

            if ($char !== '"' || $depth !== 1) {
                continue;
            }

            $close = $this->endOfString($i);

            if ($close === null) {
                break;
            }

            $name = substr($this->template, $i + 1, $close - $i - 1);
            $after = substr($this->template, $close + 1);

            if (preg_match('/^\s*:/', $after) === 1) {
                $keys[] = $name;
                $values[$name] = $this->valueAfter($close + 1);
            }

            $i = $close;
        }

        return ['keys' => array_values(array_unique($keys)), 'values' => $values];
    }

    /** Position of the quote closing the string that opens at $start, honouring escapes. */
    private function endOfString(int $start): ?int
    {
        $length = strlen($this->template);

        for ($i = $start + 1; $i < $length; $i++) {
            if ($this->template[$i] === '\\') {
                $i++;

                continue;
            }

            if ($this->template[$i] === '"') {
                return $i;
            }
        }

        return null;
    }

    /** The raw value text after a key's colon, up to the comma that ends it. */
    private function valueAfter(int $colon): string
    {
        $length = strlen($this->template);
        $i = strpos($this->template, ':', $colon);

        if ($i === false) {
            return '';
        }

        $i++;
        $depth = 0;
        $value = '';

        for (; $i < $length; $i++) {
            $char = $this->template[$i];

            if (($char === ',' || $char === '}') && $depth === 0) {
                break;
            }

            if ($char === '{' && substr($this->template, $i, 2) === '{{') {
                $close = strpos($this->template, '}}', $i);
                $value .= $close === false ? substr($this->template, $i) : substr($this->template, $i, $close + 2 - $i);
                $i = $close === false ? $length : $close + 1;

                continue;
            }

            if ($char === '{' || $char === '[') {
                $depth++;
            }

            if ($char === '}' || $char === ']') {
                $depth--;
            }

            $value .= $char;
        }

        return trim($value);
    }
}
