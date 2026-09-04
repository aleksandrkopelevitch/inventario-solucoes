<?php

namespace App\Support\Digibee\Testing;

/**
 * One synthetic call against a deployed pipeline, plus what a correct answer
 * looks like. The JSON form is §3.4's `cases[]` entry.
 *
 * Two fields carry the honesty of the whole matrix:
 *
 * - **`$covers`** names the branch the case is meant to reach, so coverage is
 *   a statement about the flowSpec rather than a count of cases.
 * - **`$blocked`** says why this case cannot be run as generated. A
 *   `choice` that routes on a downstream API's status cannot be steered by any
 *   request payload, and the tempting move — emit a plausible body anyway — is
 *   the worst available: the case runs, takes the happy path, and reports the
 *   branch as covered. A blocked case is honest coverage debt; a fabricated
 *   one is a false green.
 */
final readonly class PipelineTestCase
{
    /** @param list<Assertion> $assertions */
    public function __construct(
        public string $name,
        public TestCaseCategory $category,
        public StatusExpectation $expects,
        public array $assertions = [],
        public string $method = 'POST',
        /** @var array<string, string> */
        public array $headers = ['Content-Type' => 'application/json'],
        /**
         * An array is sent as JSON; a STRING is sent as the raw body, which is
         * the only way to express the malformed-payload case — `json_encode`
         * of broken JSON is impossible by definition.
         */
        public array|string|null $body = null,
        public ?string $covers = null,
        public ?string $blocked = null,
    ) {}

    /** Whether this case can be executed as generated, with nothing added. */
    public function runnable(): bool
    {
        return $this->blocked === null;
    }

    public function evaluate(int $status, mixed $body): CaseResult
    {
        return new CaseResult(
            case: $this,
            status: $status,
            statusMatched: $this->expects->matches($status),
            outcomes: array_map(fn (Assertion $a) => $a->evaluate($body), $this->assertions),
        );
    }

    /** @return array<string, mixed> */
    public function toArray(): array
    {
        return array_filter([
            'name'     => $this->name,
            'category' => $this->category->value,
            'covers'   => $this->covers,
            'blocked'  => $this->blocked,
            'request'  => [
                'method'  => $this->method,
                'headers' => $this->headers,
                'body'    => $this->body,
            ],
            'expected' => [
                'status'         => $this->expects->spec,
                'bodyAssertions' => array_map(fn (Assertion $a) => $a->toArray(), $this->assertions),
            ],
        ], fn ($value) => $value !== null);
    }

    /**
     * @param  array<string, mixed>  $entry
     * @param  list<string>  $errors
     */
    public static function fromArray(array $entry, array &$errors): ?self
    {
        $name = $entry['name'] ?? null;

        if (! is_string($name) || $name === '') {
            $errors[] = 'Caso de teste sem `name`.';

            return null;
        }

        $category = TestCaseCategory::tryFrom((string) ($entry['category'] ?? TestCaseCategory::Contract->value));

        if ($category === null) {
            $errors[] = "Caso \"{$name}\": `category` desconhecida \"" . ($entry['category'] ?? '') . '".';

            return null;
        }

        try {
            $expects = StatusExpectation::parse($entry['expected']['status'] ?? '2xx');
        } catch (\InvalidArgumentException $e) {
            $errors[] = "Caso \"{$name}\": " . $e->getMessage();

            return null;
        }

        $assertions = [];

        foreach ($entry['expected']['bodyAssertions'] ?? [] as $raw) {
            $assertion = is_array($raw) ? Assertion::fromArray($raw, $errors) : null;

            if ($assertion !== null) {
                $assertions[] = $assertion;
            }
        }

        return new self(
            name: $name,
            category: $category,
            expects: $expects,
            assertions: $assertions,
            method: strtoupper((string) ($entry['request']['method'] ?? 'POST')),
            headers: is_array($entry['request']['headers'] ?? null) ? $entry['request']['headers'] : ['Content-Type' => 'application/json'],
            body: is_array($entry['request']['body'] ?? null) || is_string($entry['request']['body'] ?? null) ? $entry['request']['body'] : null,
            covers: is_string($entry['covers'] ?? null) ? $entry['covers'] : null,
            blocked: is_string($entry['blocked'] ?? null) ? $entry['blocked'] : null,
        );
    }
}
