<?php

namespace App\Support\Digibee\Testing;

/**
 * One execution of a test matrix against a deployed pipeline — the artifact
 * block F's correction loop reads instead of a static validation error.
 *
 * It reports three populations rather than a pass rate: what ran and passed,
 * what ran and failed, and what was never sent. Collapsing the third into a
 * failure is how a suite starts reporting a missing test credential, or a CPF
 * nobody could supply, as a defect in the pipeline.
 */
final readonly class SuiteRun
{
    /**
     * @param  list<CaseResult>  $results  cases that were actually sent
     * @param  list<PipelineTestCase>  $skipped  cases held back, never sent
     */
    public function __construct(
        public PipelineTestSuite $suite,
        public string $url,
        public array $results = [],
        public array $skipped = [],
        public bool $authenticated = false,
    ) {}

    /** @return list<CaseResult> */
    public function failed(): array
    {
        return array_values(array_filter($this->results, fn (CaseResult $r) => ! $r->passed()));
    }

    public function passed(): bool
    {
        return $this->results !== [] && $this->failed() === [];
    }

    /**
     * Every failure as a line the loop can be re-prompted with, prefixed by
     * the case it came from — a bare "esperava 2xx, veio 500" is unusable when
     * eleven cases ran.
     *
     * @return list<string>
     */
    public function failures(): array
    {
        $lines = [];

        foreach ($this->failed() as $result) {
            foreach ($result->failures() as $failure) {
                $lines[] = "[{$result->case->name}] {$failure}";
            }
        }

        return $lines;
    }

    /**
     * Whether this run looks like it was refused at the door rather than
     * executed — every case answering 401/403 with no credential given.
     *
     * The distinction is the difference between a correction loop rewriting a
     * pipeline and somebody being told to pass the endpoint's API key. A wall
     * of 401s is the single most misleading input this feature can hand a
     * model, because it looks exactly like a pipeline that rejects everything.
     */
    public function refusedForCredentials(): bool
    {
        if ($this->authenticated || $this->results === []) {
            return false;
        }

        foreach ($this->results as $result) {
            if (! in_array($result->status, [401, 403], true)) {
                return false;
            }
        }

        return true;
    }

    /** @return array{ran: int, passed: int, failed: int, skipped: int} */
    public function tally(): array
    {
        return [
            'ran'     => count($this->results),
            'passed'  => count($this->results) - count($this->failed()),
            'failed'  => count($this->failed()),
            'skipped' => count($this->skipped),
        ];
    }
}
