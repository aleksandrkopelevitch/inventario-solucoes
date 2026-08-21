<?php

namespace App\Enums;

/**
 * The two kinds of context an Especialista em Integrações conversation accepts
 * — plus the split inside the second one.
 *
 * `Document` is a reference to documentation already in the inventory, read
 * live. `File` and `Text` are both "material the user brought": they differ
 * only in where the bytes came from (an upload vs. a long paste in the
 * composer), which is why they share every downstream code path and are shown
 * in the same list. There is deliberately no fourth kind: a pasted flowSpec
 * document is a `Text` carrying `is_flowspec_reference`, not a type of its own.
 */
enum FlowspecAttachmentKind: string
{
    case Document = 'document';
    case File = 'file';
    case Text = 'text';

    public function label(): string
    {
        return match ($this) {
            self::Document => 'Documento do inventário',
            self::File     => 'Arquivo',
            self::Text     => 'Texto colado',
        };
    }

    /** Heroicon name (outline), rendered via x-dynamic-component. */
    public function icon(): string
    {
        return match ($this) {
            self::Document => 'document-text',
            self::File     => 'paper-clip',
            self::Text     => 'document',
        };
    }

    /** Text this attachment contributes is copied into `content` (as opposed to read live from a reference). */
    public function carriesOwnContent(): bool
    {
        return $this !== self::Document;
    }
}
