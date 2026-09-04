<?php

namespace App\Support\Digibee\Testing;

/**
 * One case, run. `failures()` is what the self-healing loop is handed, so every
 * line of it has to be re-promptable on its own: which case, what was expected,
 * what came back.
 */
final readonly class CaseResult
{
    /** @param list<AssertionOutcome> $outcomes */
    public function __construct(
        public PipelineTestCase $case,
        public int $status,
        public bool $statusMatched,
        public array $outcomes = [],
    ) {}

    public function passed(): bool
    {
        return $this->statusMatched && $this->failedAssertions() === [];
    }

    /** @return list<AssertionOutcome> */
    public function failedAssertions(): array
    {
        return array_values(array_filter($this->outcomes, fn (AssertionOutcome $o) => ! $o->passed));
    }

    /** @return list<string> PT-BR, one line per thing that went wrong */
    public function failures(): array
    {
        $failures = [];

        if (! $this->statusMatched) {
            $failures[] = "Esperava {$this->case->expects->describe()}, veio {$this->status}.";
        }

        foreach ($this->failedAssertions() as $outcome) {
            $failures[] = $outcome->reason;
        }

        return $failures;
    }
}
