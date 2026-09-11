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
        return $this->results !== []
            && $this->failed() === []
            && ! $this->nothingAnswered();
    }

    /**
     * Every case came back 404 — which is not a pipeline handling bad input,
     * it is nothing being there.
     *
     * This exists because the suite produced a false green in exactly that
     * situation: three `!5xx` cases fired at an undeployed pipeline, each got
     * a 404, each "passed", and the run reported itself green. A 404 is a
     * legitimate answer FROM a pipeline (a route that rejects an unknown
     * path), so the signal is not the status alone — it is every single case
     * getting it.
     */
    public function nothingAnswered(): bool
    {
        if ($this->results === []) {
            return false;
        }

        foreach ($this->results as $result) {
            if ($result->status !== 404) {
                return false;
            }
        }

        return true;
    }

    /**
     * Whether the case that actually proves the pipeline WORKS was run and
     * passed.
     *
     * A suite whose happy path is blocked (waiting on a real CPF, say) can
     * still be all-green on its negative cases, and calling that "green" says
     * the pipeline works when nothing has shown that it does. The tally is
     * honest; the word is not.
     */
    public function provenByHappyPath(): bool
    {
        foreach ($this->results as $result) {
            if ($result->case->category === TestCaseCategory::HappyPath && $result->passed()) {
                return true;
            }
        }

        return false;
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
