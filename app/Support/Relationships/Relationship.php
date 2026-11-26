<?php

namespace App\Support\Relationships;

use App\Enums\RelationshipDirection;
use App\Models\DocumentationPage;
use App\Models\Solution;

/**
 * One relationship between two catalog solutions, oriented from `$solution`
 * (the one asking). Built by SolutionRelationshipService; a value, never
 * stored.
 *
 * `$direct` is false for a pair known only through an integration platform
 * (`$via`): "SAP S/4 → Freshdesk, via SAP CPI".
 */
final readonly class Relationship
{
    /**
     * @param  list<Solution>  $via  the platforms in the middle, in order, when not direct
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
    ) {}

    /** The arrow as words, from this relationship's own side. */
    public function sentence(): string
    {
        return $this->direction->sentence($this->solution->name, $this->other->name);
    }
}
