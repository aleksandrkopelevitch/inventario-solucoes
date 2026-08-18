<?php

namespace App\Enums;

/**
 * How much of a section is actually somebody's word.
 *
 * The distinction is the whole trust model of a generated document: `Drafted`
 * means the assistant proposed the text and nobody has signed it, `Confirmed`
 * means a human read it and took responsibility. The ticket's final checklist
 * is derived from this (only `Confirmed` ticks a box), and the UI keeps a
 * visible mark on anything still `Drafted`.
 */
enum SubmissionSectionState: string
{
    case Empty = 'empty';
    case Drafted = 'drafted';
    case Confirmed = 'confirmed';

    public function label(): string
    {
        return match ($this) {
            self::Empty     => 'Em branco',
            self::Drafted   => 'Rascunho da IA',
            self::Confirmed => 'Confirmada',
        };
    }

    /** Literal classes — see the note on SubmissionStatus::badgeClass(). */
    public function badgeClass(): string
    {
        return match ($this) {
            self::Empty     => 'bg-raised text-muted ring-1 ring-line',
            self::Drafted   => 'bg-cat-amber-soft text-cat-amber-ink ring-1 ring-cat-amber-line',
            self::Confirmed => 'bg-cat-emerald-soft text-cat-emerald-ink ring-1 ring-cat-emerald-line',
        };
    }

    /** @return array<int, array{value: string, label: string}> */
    public static function options(): array
    {
        return array_map(
            fn (self $state) => ['value' => $state->value, 'label' => $state->label()],
            self::cases(),
        );
    }
}
