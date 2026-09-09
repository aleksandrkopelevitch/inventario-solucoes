<?php

namespace App\Support\Digibee\Testing;

/**
 * One case, run. `failures()` is what the self-healing loop is handed, so every
 * line of it has to be re-promptable on its own: which case, what was expected,
 * what came back.
 */
final readonly class CaseResult
{
    /**
     * @param  list<AssertionOutcome>  $outcomes
     * @param  string|null  $contentType  the response's media type, or null when
     *                                    the caller had no headers to read
     */
    public function __construct(
        public PipelineTestCase $case,
        public int $status,
        public bool $statusMatched,
        public array $outcomes = [],
        public ?string $contentType = null,
    ) {}

    public function passed(): bool
    {
        return $this->statusMatched && $this->failedAssertions() === [] && $this->contentTypeMatched() !== false;
    }

    /**
     * Whether the response carried the declared media type — null when there
     * was nothing to compare, which is not the same as a pass.
     *
     * A missing header with a claim standing is a FAILURE, deliberately: the
     * caller's parser needs the type, and "the pipeline sent none" is exactly
     * the bug this claim exists to catch. Only an evaluation with no headers
     * at all (nobody read them) answers null.
     */
    public function contentTypeMatched(): ?bool
    {
        $expected = $this->case->expectsContentType;

        if ($expected === null) {
            return null;
        }

        return $this->contentType === strtolower($expected);
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

        if ($this->contentTypeMatched() === false) {
            $failures[] = "Esperava Content-Type {$this->case->expectsContentType}, veio "
                . ($this->contentType ?? 'nenhum') . '.';
        }

        foreach ($this->failedAssertions() as $outcome) {
            $failures[] = $outcome->reason;
        }

        return $failures;
    }
}
