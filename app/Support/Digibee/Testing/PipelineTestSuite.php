<?php

namespace App\Support\Digibee\Testing;

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
    ) {}

    /**
     * Where the deployed pipeline is called.
     *
     * **Unverified**, and the one piece of this module that is: §3.4 states
     * `{host}/pipeline/{realm}/{environment}/v1/{pipelineName}`, and the local
     * export cannot corroborate it — `previewURL` is empty on all 201
     * pipelines. So the host is configuration rather than a literal, and the
     * first real run is what confirms the path.
     */
    public function endpointUrl(string $realm): string
    {
        $host = rtrim((string) config('services.digibee.design.runtime_url'), '/');

        return "{$host}/pipeline/{$realm}/{$this->environment}/v1/{$this->pipelineName}";
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
            'testSuite'    => $this->name,
            'pipelineName' => $this->pipelineName,
            'environment'  => $this->environment,
            'cases'        => array_map(fn (PipelineTestCase $case) => $case->toArray(), $this->cases),
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
        );
    }
}
