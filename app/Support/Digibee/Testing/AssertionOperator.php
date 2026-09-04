<?php

namespace App\Support\Digibee\Testing;

/**
 * How one assertion compares what came back to what was expected.
 *
 * The set is small on purpose. These assertions are written against a pipeline
 * nobody has run yet, from a flowSpec — so the honest comparisons are the ones
 * about SHAPE and about a value the flowSpec itself names (a literal in a JSLT
 * mapping, a status a `choice` routes on). A richer vocabulary would invite
 * assertions nobody can justify from the document they were derived from.
 *
 * `Exists`/`Missing` are the two that need no expected value, which is why
 * `needsValue()` exists rather than every arm defending against null.
 */
enum AssertionOperator: string
{
    case Equals = 'equals';
    case NotEquals = 'notEquals';
    case Contains = 'contains';
    case NotContains = 'notContains';
    case Exists = 'exists';
    case Missing = 'missing';
    case Matches = 'matches';
    case GreaterThan = 'gt';
    case AtLeast = 'gte';
    case LessThan = 'lt';
    case AtMost = 'lte';

    public function needsValue(): bool
    {
        return ! in_array($this, [self::Exists, self::Missing], true);
    }

    /**
     * @param  list<mixed>  $nodes  what the path matched — empty means absent,
     *                              and only Exists/Missing may draw a
     *                              conclusion from that
     */
    public function evaluate(array $nodes, mixed $expected): bool
    {
        if ($this === self::Missing) {
            return $nodes === [];
        }

        if ($nodes === []) {
            // Every other operator is a statement ABOUT a value, and there
            // isn't one. `notEquals` included: "the field differs from x" is
            // not something an absent field satisfies, it is something a test
            // asking the wrong question produces.
            return false;
        }

        if ($this === self::Exists) {
            return true;
        }

        // A wildcard path matches many nodes; an assertion holds when ANY of
        // them does. That is what makes `$.itens[*].status equals OK` read the
        // way somebody writing it expects.
        foreach ($nodes as $node) {
            if ($this->compare($node, $expected)) {
                return true;
            }
        }

        return false;
    }

    private function compare(mixed $actual, mixed $expected): bool
    {
        return match ($this) {
            // Strict, so `0`, `false`, `""` and `null` stay four different
            // answers — a pipeline reporting `sucesso: false` and one omitting
            // the field are not the same bug.
            self::Equals      => $actual === $expected,
            self::NotEquals   => $actual !== $expected,
            self::Contains    => $this->contains($actual, $expected),
            self::NotContains => ! $this->contains($actual, $expected),
            self::Matches     => is_string($actual) && is_string($expected) && @preg_match($expected, $actual) === 1,
            self::GreaterThan => is_numeric($actual) && is_numeric($expected) && $actual > $expected,
            self::AtLeast     => is_numeric($actual) && is_numeric($expected) && $actual >= $expected,
            self::LessThan    => is_numeric($actual) && is_numeric($expected) && $actual < $expected,
            self::AtMost      => is_numeric($actual) && is_numeric($expected) && $actual <= $expected,
            default           => false,
        };
    }

    /**
     * Substring for a string, membership for a list — both are what somebody
     * means by "contains", and which one applies is decided by the RESPONSE
     * rather than declared in the assertion, because the same field can come
     * back as a string or as a list depending on the branch taken.
     */
    private function contains(mixed $actual, mixed $expected): bool
    {
        if (is_string($actual)) {
            return is_scalar($expected) && str_contains($actual, (string) $expected);
        }

        return is_array($actual) && in_array($expected, $actual, true);
    }

    /** PT-BR, because this reaches the test report a person reads. */
    public function describe(): string
    {
        return match ($this) {
            self::Equals      => 'deve ser igual a',
            self::NotEquals   => 'deve ser diferente de',
            self::Contains    => 'deve conter',
            self::NotContains => 'não deve conter',
            self::Exists      => 'deve existir no corpo',
            self::Missing     => 'não deve existir no corpo',
            self::Matches     => 'deve casar com a expressão',
            self::GreaterThan => 'deve ser maior que',
            self::AtLeast     => 'deve ser no mínimo',
            self::LessThan    => 'deve ser menor que',
            self::AtMost      => 'deve ser no máximo',
        };
    }
}
