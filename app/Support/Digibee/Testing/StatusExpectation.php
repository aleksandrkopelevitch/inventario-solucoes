<?php

namespace App\Support\Digibee\Testing;

use InvalidArgumentException;

/**
 * What HTTP status a case accepts — `200`, `2xx`, `2xx|4xx`, `!5xx`.
 *
 * §3.4's schema writes `"status": 200`, and an exact status is the right
 * expectation for a case whose payload was derived. It is the WRONG one for
 * every resilience case, and that is why this type exists: asked to send a
 * malformed body, nobody knows whether a correct pipeline answers 400, or 200
 * with an error object, or 422 — the platform and the author decide that
 * together. What is a defect in all three worlds is an unhandled **500**.
 *
 * So `!5xx` is a real expectation and not a weak one: it is the assertion
 * "this input is handled rather than crashing", which is exactly what a
 * resilience case is for. Demanding an exact status there would make the whole
 * category report failures that are only disagreements about a contract nobody
 * wrote down — and a self-healing loop fed those would patch working pipelines.
 */
final readonly class StatusExpectation
{
    /**
     * @param  list<string>  $allow  families/exact codes that pass
     * @param  list<string>  $deny  families/exact codes that fail
     */
    private function __construct(
        public string $spec,
        private array $allow,
        private array $deny,
    ) {}

    public static function parse(int|string $spec): self
    {
        $spec = trim((string) $spec);

        if ($spec === '') {
            throw new InvalidArgumentException('Empty status expectation.');
        }

        $allow = [];
        $deny = [];

        foreach (explode('|', $spec) as $term) {
            $term = trim($term);
            $negated = str_starts_with($term, '!');
            $code = $negated ? substr($term, 1) : $term;

            if (preg_match('/^(\d{3}|[1-5]xx)$/i', $code) !== 1) {
                throw new InvalidArgumentException("Unsupported status expectation \"{$term}\" — use 200, 2xx, 2xx|4xx or !5xx.");
            }

            $negated ? $deny[] = strtolower($code) : $allow[] = strtolower($code);
        }

        return new self($spec, $allow, $deny);
    }

    public static function ok(): self
    {
        return self::parse('2xx');
    }

    /** The resilience default: anything the pipeline HANDLED. */
    public static function handled(): self
    {
        return self::parse('!5xx');
    }

    public function matches(int $status): bool
    {
        foreach ($this->deny as $code) {
            if ($this->covers($code, $status)) {
                return false;
            }
        }

        // No positive term means the expectation was purely an exclusion
        // ("anything but a 5xx"), which is a complete statement on its own.
        if ($this->allow === []) {
            return true;
        }

        foreach ($this->allow as $code) {
            if ($this->covers($code, $status)) {
                return true;
            }
        }

        return false;
    }

    private function covers(string $code, int $status): bool
    {
        return str_ends_with($code, 'xx')
            ? intdiv($status, 100) === (int) $code[0]
            : (int) $code === $status;
    }

    /** PT-BR: this reaches the report. */
    public function describe(): string
    {
        $family = fn (string $code) => str_ends_with($code, 'xx') ? "{$code[0]}xx" : $code;

        if ($this->allow === []) {
            return 'qualquer status que não seja ' . implode(' nem ', array_map($family, $this->deny));
        }

        $allowed = 'status ' . implode(' ou ', array_map($family, $this->allow));

        return $this->deny === []
            ? $allowed
            : $allowed . ', e nunca ' . implode(' nem ', array_map($family, $this->deny));
    }
}
