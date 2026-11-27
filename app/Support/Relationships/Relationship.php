<?php

namespace App\Support\Relationships;

use App\Enums\RelationshipDirection;
use App\Models\DocumentationPage;
use App\Models\Solution;
use App\Models\SolutionRelationship;

/**
 * One relationship between two catalog solutions, oriented from `$solution`
 * (the one asking). Built by SolutionRelationshipService; a value, never
 * stored.
 *
 * `$direct` is false for a pair known only through an integration platform
 * (`$via`): "SAP S/4 → Freshdesk, via SAP CPI".
 *
 * `$manual` is the interconnection a person recorded for this pair, which
 * decides `$direction` when present; `$documented` is then what the
 * documentation says on its own (null when it says nothing), so the screen can
 * show what the record overrides.
 */
final readonly class Relationship
{
    /**
     * @param  list<list<Solution>>  $via  the routes through platforms, each read from `$solution` to `$other`
     * @param  list<string>  $labels  up to SolutionRelationshipService::MAX_LABELS
     * @param  list<array{page: DocumentationPage, position: int, title: ?string, type: string}>  $evidence
     */
    public function __construct(
        public Solution $solution,
        public Solution $other,
        public RelationshipDirection $direction,
        public bool $direct,
        public array $via,
        public array $labels,
        public array $evidence,
        public ?SolutionRelationship $manual = null,
        public ?RelationshipDirection $documented = null,
    ) {}

    /**
     * Where the answer comes from: the documentation alone, a person alone,
     * or a person correcting the documentation.
     */
    public function source(): string
    {
        return match (true) {
            $this->manual === null     => 'documented',
            $this->documented !== null => 'adjusted',
            default                    => 'manual',
        };
    }

    /** The arrow as words, from this relationship's own side. */
    public function sentence(): string
    {
        return $this->direction->sentence($this->solution->name, $this->other->name);
    }
}
