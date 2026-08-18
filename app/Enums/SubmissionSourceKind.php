<?php

namespace App\Enums;

/**
 * Where a piece of gathered material came from. `Inventory` is the one that
 * costs nothing to add and is worth the most: it points at a Solution,
 * Integration or DocumentationPage this app already holds, so the interview
 * reads it directly instead of asking the user to paste it.
 */
enum SubmissionSourceKind: string
{
    case Upload = 'upload';
    case Link = 'link';
    case Inventory = 'inventory';

    public function label(): string
    {
        return match ($this) {
            self::Upload    => 'Arquivo',
            self::Link      => 'Link',
            self::Inventory => 'Do inventário',
        };
    }

    public function icon(): string
    {
        return match ($this) {
            self::Upload    => 'paper-clip',
            self::Link      => 'link',
            self::Inventory => 'squares-2x2',
        };
    }
}
