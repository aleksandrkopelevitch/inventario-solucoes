<?php

namespace App\Support\Digibee\Testing;

use App\Exceptions\DigibeeApiException;

/**
 * A whole test matrix for one pipeline in one environment — §3.4's `testSuite`
 * document, and the artifact that travels between the generator, the runner and
 * the self-healing loop.
 *
 * `coverage()` is the number worth reading, and it deliberately reports what is
 * RUNNABLE against what the flowSpec needs. A suite of twelve cases where eight
 * are blocked on a downstream system is not 100% of anything, and printing "12
 * cases" would be the same false green a fabricated payload produces.
 */
final readonly class PipelineTestSuite
{
    /** @param list<PipelineTestCase> $cases */
    public function __construct(
        public string $name,
        public string $pipelineName,
        public string $environment,
        public array $cases = [],
        /**
         * The pipeline's MAJOR version, which is a segment of its own URL
         * (`/v{n}/`) rather than a literal `v1`. Derived from the pipeline
         * document when there is one; a freshly generated `{meta, flowSpec}`
         * has no version yet, so 1 is the right default for it.
         */
        public int $versionMajor = 1,
    ) {}

    /**
     * Where the deployed pipeline is called:
     * `https://{test|api}.godigibee.io/pipeline/{realm}/v{n}/{pipelineName}`.
     *
     * **The environment is the HOST, not a path segment.** The APLA spec
     * writes it the other way round — one host with `/{environment}/` in the
     * path — and Digibee's REST trigger reference contradicts that on three
     * separate documentation pages. The distinction is a safety property, not
     * a cosmetic one: written the spec's way, the surplus path segment 404s,
     * and the obvious "fix" of deleting it sends every call for the `test`
     * environment to PRODUCTION while the report still says test. So the
     * host is looked up per environment and an unmapped one is REFUSED.
     *
     * @throws DigibeeApiException
     */
    public function endpointUrl(string $realm): string
    {
        $host = config("services.digibee.design.runtime_hosts.{$this->environment}");

        if (! is_string($host) || $host === '') {
            throw DigibeeApiException::unknownEnvironment($this->environment);
        }

        return rtrim($host, '/') . "/pipeline/{$realm}/v{$this->versionMajor}/{$this->pipelineName}";
    }

    /** @return list<PipelineTestCase> the cases that can run with nothing added */
    public function runnable(): array
    {
        return array_values(array_filter($this->cases, fn (PipelineTestCase $case) => $case->runnable()));
    }

    /** @return list<PipelineTestCase> the cases still owing a payload, a mock or a real value */
    public function blocked(): array
    {
        return array_values(array_filter($this->cases, fn (PipelineTestCase $case) => ! $case->runnable()));
    }

    /** @return array{runnable: int, blocked: int, total: int} */
    public function coverage(): array
    {
        return [
            'runnable' => count($this->runnable()),
            'blocked'  => count($this->blocked()),
            'total'    => count($this->cases),
        ];
    }

    /** @return list<PipelineTestCase> */
    public function inCategory(TestCaseCategory $category): array
    {
        return array_values(array_filter($this->cases, fn (PipelineTestCase $case) => $case->category === $category));
    }

    /** @return array<string, mixed> */
    public function toArray(): array
    {
        return [
            'testSuite'            => $this->name,
            'pipelineName'         => $this->pipelineName,
            'pipelineVersionMajor' => $this->versionMajor,
            'environment'          => $this->environment,
            'cases'                => array_map(fn (PipelineTestCase $case) => $case->toArray(), $this->cases),
        ];
    }

    /**
     * @param  array<string, mixed>  $document
     * @param  list<string>  $errors  collected, PT-BR, re-promptable — a model
     *                                writes these documents from Phase F on
     */
    public static function fromArray(array $document, array &$errors): self
    {
        $cases = [];

        foreach ($document['cases'] ?? [] as $raw) {
            $case = is_array($raw) ? PipelineTestCase::fromArray($raw, $errors) : null;

            if ($case !== null) {
                $cases[] = $case;
            }
        }

        if ($cases === []) {
            $errors[] = 'A suíte não tem nenhum caso de teste válido.';
        }

        return new self(
            name: (string) ($document['testSuite'] ?? 'suite'),
            pipelineName: (string) ($document['pipelineName'] ?? ''),
            environment: (string) ($document['environment'] ?? 'test'),
            cases: $cases,
            versionMajor: (int) ($document['pipelineVersionMajor'] ?? 1),
        );
    }
}
