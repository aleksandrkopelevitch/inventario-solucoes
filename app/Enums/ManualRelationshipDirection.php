<?php

namespace App\Enums;

/**
 * How a manual interconnection runs, read from solution A's side — A being
 * the LOWER id of the pair, which is how `solution_relationships` stores it.
 * Screens and tools never speak this language: they speak
 * RelationshipDirection, from the side of whoever is asking, and
 * `fromSide()`/`fromSideOf()` translate.
 */
enum ManualRelationshipDirection: string
{
    case AToB = 'a_to_b';
    case BToA = 'b_to_a';
    case Both = 'both';

    /** `$direction` as seen from A (`$isA`) or from B, in the stored language. */
    public static function fromSide(RelationshipDirection $direction, bool $isA): self
    {
        return match ($direction) {
            RelationshipDirection::Outgoing => $isA ? self::AToB : self::BToA,
            RelationshipDirection::Incoming => $isA ? self::BToA : self::AToB,
            default                         => self::Both,
        };
    }

    /** The stored value as seen from A (`$isA`) or from B. */
    public function asSeenFrom(bool $isA): RelationshipDirection
    {
        return match ($this) {
            self::AToB => $isA ? RelationshipDirection::Outgoing : RelationshipDirection::Incoming,
            self::BToA => $isA ? RelationshipDirection::Incoming : RelationshipDirection::Outgoing,
            self::Both => RelationshipDirection::Both,
        };
    }

    public function aToB(): bool
    {
        return $this !== self::BToA;
    }

    public function bToA(): bool
    {
        return $this !== self::AToB;
    }
}
