<?php

namespace App\Support\Digibee\Testing;

/**
 * One assertion, evaluated. `actual` is what the path matched, kept because the
 * self-healing loop is only as good as the evidence it is handed: "esperava
 * `false`, veio `true`" is a diagnosable failure and "assertion failed" is not.
 */
final readonly class AssertionOutcome
{
    public function __construct(
        public Assertion $assertion,
        public bool $passed,
        /** @var list<mixed> */
        public array $actual,
        public string $reason,
    ) {}
}
