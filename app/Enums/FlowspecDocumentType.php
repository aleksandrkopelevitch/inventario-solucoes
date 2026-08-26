<?php

namespace App\Enums;

use App\Models\DocumentationPage;
use InvalidArgumentException;

/**
 * What a conversation can attach FROM the inventory, and the one place that
 * maps between how it is written and what it is.
 *
 * References travel as `page:12` — the picker's checkbox values, the suggestion
 * buttons' payload, and the `documents[]` the attach endpoints accept — but
 * they are STORED as a morph pair (`reference_type`, `reference_id`). Something
 * has to translate, and before this enum four places each carried their own
 * copy of the same `=== 'page' ? DocumentationPage` ternary: the picker's
 * rendering, the resolver's dedupe, the context guard's sizing and the attach
 * action. Four copies of a two-line mapping is three chances for the guard to
 * compare a reference against a key it can never equal, which reads as "this
 * document isn't attached" — and silently double-counts it.
 *
 * **One case, on purpose.** There were two: `page` and `integration`, the
 * latter reaching an integration's own `documentation` column. That second kind
 * of documentation is gone — a `Diagram` is a drawing and carries no prose — so
 * everything attachable from the inventory is a page. The enum stays because
 * the four call sites above still need one translation table, and because
 * `reference_*` is still a morph pair on the wire and in storage.
 */
enum FlowspecDocumentType: string
{
    case Page = 'page';

    /** @return class-string<DocumentationPage> */
    public function modelClass(): string
    {
        return match ($this) {
            self::Page => DocumentationPage::class,
        };
    }

    public static function forMorphClass(string $morphClass): self
    {
        return match ($morphClass) {
            DocumentationPage::class => self::Page,
            // Loud rather than defaulting: a reference to something that isn't
            // attachable used to fall through to the other case and be sized,
            // compared and rendered as if it were.
            default => throw new InvalidArgumentException("Not an attachable documentation type: {$morphClass}"),
        };
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
