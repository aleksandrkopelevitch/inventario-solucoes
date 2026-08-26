<?php

namespace App\Enums;

/**
 * Where a piece of gathered material came from. `Inventory` is the one that
 * costs nothing to add and is worth the most: it points at a Solution,
 * Diagram or DocumentationPage this app already holds, so the interview
 * reads it directly instead of asking the user to paste it.
 *
 * `Text` is what a long paste into the interview's composer becomes — the
 * Claude client's behaviour. It is material like any other, not a message:
 * pasting a whole architecture document into the chat box would bury the
 * conversation, be re-sent verbatim on every turn as history, and leave
 * nothing to remove afterwards.
 */
enum SubmissionSourceKind: string
{
    case Upload = 'upload';
    case Link = 'link';
    case Text = 'text';
    case Inventory = 'inventory';

    public function label(): string
    {
        return match ($this) {
            self::Upload    => 'Arquivo',
            self::Link      => 'Link',
            self::Text      => 'Texto colado',
            self::Inventory => 'Do inventário',
        };
    }

    public function icon(): string
    {
        return match ($this) {
            self::Upload    => 'paper-clip',
            self::Link      => 'link',
            self::Text      => 'clipboard-document',
            self::Inventory => 'squares-2x2',
        };
    }
}
