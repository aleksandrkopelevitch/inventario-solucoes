<?php

namespace App\Enums;

use App\Models\DocumentationPage;
use App\Models\Integration;

/**
 * The two things a conversation can attach FROM the inventory, and the one
 * place that maps between how they are written and what they are.
 *
 * They travel as `page:12` / `integration:7` — the picker's checkbox values,
 * the suggestion buttons' payload, and the `documents[]` the attach endpoints
 * accept — but they are STORED as a morph pair (`reference_type`,
 * `reference_id`). Something has to translate, and before this enum four places
 * each carried their own copy of the same `=== 'page' ? DocumentationPage`
 * ternary: the picker's rendering, the resolver's dedupe, the context guard's
 * sizing and the attach action. Four copies of a two-line mapping is three
 * chances for the guard to compare a reference against a key it can never
 * equal, which reads as "this document isn't attached" — and silently
 * double-counts it.
 */
enum FlowspecDocumentType: string
{
    case Page = 'page';
    case Integration = 'integration';

    /** @return class-string<DocumentationPage|Integration> */
    public function modelClass(): string
    {
        return match ($this) {
            self::Page        => DocumentationPage::class,
            self::Integration => Integration::class,
        };
    }

    public static function forMorphClass(string $morphClass): self
    {
        return $morphClass === DocumentationPage::class ? self::Page : self::Integration;
    }

    /** How the pair is stored, and therefore how two references are compared. */
    public function morphKey(int|string $id): string
    {
        return "{$this->modelClass()}:{$id}";
    }

    /** How the pair is written on the wire, back to the picker's own shape. */
    public function reference(int|string $id): string
    {
        return "{$this->value}:{$id}";
    }
}
