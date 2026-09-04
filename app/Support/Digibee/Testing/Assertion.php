<?php

namespace App\Support\Digibee\Testing;

/**
 * One expectation about a response body: a path, an operator, a value.
 *
 * Mirrors §3.4's `bodyAssertions` entries one for one, so a suite persisted as
 * JSON is the same document the spec describes.
 */
final readonly class Assertion
{
    public function __construct(
        public string $jsonPath,
        public AssertionOperator $operator,
        public mixed $value = null,
    ) {}

    /**
     * Reads one assertion out of the JSON shape, or returns the reason it
     * cannot be read.
     *
     * Returning the reason rather than throwing is what lets a whole suite be
     * validated at once — and it matters more here than it looks, because
     * later phases have a MODEL writing these. A model that invents an
     * operator or pastes a `choice` filter into `jsonPath` has to get the same
     * concrete, re-promptable error the flowSpec validator already produces,
     * not an exception that ends the turn.
     *
     * @param  array<string, mixed>  $entry
     * @param  list<string>  $errors
     */
    public static function fromArray(array $entry, array &$errors): ?self
    {
        $path = $entry['jsonPath'] ?? null;
        $operator = AssertionOperator::tryFrom((string) ($entry['operator'] ?? ''));

        if (! is_string($path) || $path === '') {
            $errors[] = 'Asserção sem `jsonPath`.';

            return null;
        }

        if (! JsonPath::supports($path)) {
            $errors[] = "Asserção com `jsonPath` fora do subconjunto suportado: \"{$path}\" — filtros, descida recursiva e slices não são aceitos.";

            return null;
        }

        if ($operator === null) {
            $valid = implode(', ', array_column(AssertionOperator::cases(), 'value'));
            $errors[] = "Asserção em \"{$path}\" com `operator` desconhecido \"" . ($entry['operator'] ?? '') . "\" — válidos: {$valid}.";

            return null;
        }

        if ($operator->needsValue() && ! array_key_exists('value', $entry)) {
            $errors[] = "Asserção em \"{$path}\" com operador `{$operator->value}` exige `value`.";

            return null;
        }

        return new self($path, $operator, $entry['value'] ?? null);
    }

    /** @param mixed $body the decoded response body */
    public function evaluate(mixed $body): AssertionOutcome
    {
        $nodes = JsonPath::find($body, $this->jsonPath);
        $passed = $this->operator->evaluate($nodes, $this->value);

        return new AssertionOutcome(
            assertion: $this,
            passed: $passed,
            actual: $nodes,
            reason: $passed ? $this->describe() : $this->explainFailure($nodes),
        );
    }

    public function describe(): string
    {
        return "`{$this->jsonPath}` {$this->operator->describe()}"
            . ($this->operator->needsValue() ? ' `' . $this->render($this->value) . '`' : '');
    }

    /** @param list<mixed> $nodes */
    private function explainFailure(array $nodes): string
    {
        if ($nodes === [] && $this->operator !== AssertionOperator::Missing) {
            return "`{$this->jsonPath}` não existe no corpo da resposta.";
        }

        if ($this->operator === AssertionOperator::Missing) {
            return "`{$this->jsonPath}` existe no corpo e não deveria (veio `" . $this->render($nodes[0]) . '`).';
        }

        $actual = count($nodes) === 1
            ? '`' . $this->render($nodes[0]) . '`'
            : implode(', ', array_map(fn ($node) => '`' . $this->render($node) . '`', $nodes));

        return "{$this->describe()}, veio {$actual}.";
    }

    /**
     * Values reach a report, so they are rendered rather than dumped: `false`
     * printed as an empty string is how somebody reads a correct assertion as
     * a broken one.
     */
    private function render(mixed $value): string
    {
        return match (true) {
            is_bool($value)   => $value ? 'true' : 'false',
            is_null($value)   => 'null',
            is_scalar($value) => (string) $value,
            default           => (string) json_encode($value, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
        };
    }

    /** @return array<string, mixed> */
    public function toArray(): array
    {
        $entry = ['jsonPath' => $this->jsonPath, 'operator' => $this->operator->value];

        return $this->operator->needsValue() ? [...$entry, 'value' => $this->value] : $entry;
    }
}
