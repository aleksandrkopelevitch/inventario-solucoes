<?php

namespace App\Enums;

enum DiagramStatus: string
{
    case Active = 'active';
    case InDevelopment = 'in_development';
    case Planned = 'planned';
    case Deprecated = 'deprecated';

    public function label(): string
    {
        return match ($this) {
            self::Active        => 'Ativa',
            self::InDevelopment => 'Em desenvolvimento',
            self::Planned       => 'Planejada',
            self::Deprecated    => 'Descontinuada',
        };
    }

    /**
     * Soft badge for the status, in the `soft/ink/line` shape every other
     * metadata badge in the app uses (same tone vocabulary as the Solution
     * header's attribute chips).
     *
     * The class strings MUST stay literal and this file MUST stay listed in
     * `resources/css/app.css`'s `@source` block — same contract as
     * `App\Support\CategoryPalette`. Building one by concatenation would never
     * be scanned by Tailwind's JIT and the badge would render uncolored.
     *
     * It lives here, rather than in each Blade that shows a status, because the
     * badge is now drawn in two places at once (the solution's diagrams
     * column and the diagram editor's top bar) and they must never drift.
     * It also replaces an older `group-data-[status=…]:` variant chain, which
     * only existed so JS could recolor a row by swapping one attribute — the
     * canvas stopped patching those rows when it moved to its own page.
     */
    public function badgeClass(): string
    {
        return match ($this) {
            self::Active        => 'bg-cat-emerald-soft text-cat-emerald-ink ring-1 ring-cat-emerald-line',
            self::InDevelopment => 'bg-hot-soft text-hot ring-1 ring-hot-line',
            self::Planned       => 'bg-cat-blue-soft text-cat-blue-ink ring-1 ring-cat-blue-line',
            self::Deprecated    => 'bg-raised text-muted ring-1 ring-line',
        };
    }

    /** The same tone as a solid dot — the quick visual anchor on a list row. */
    public function dotClass(): string
    {
        return match ($this) {
            self::Active        => 'bg-cat-emerald',
            self::InDevelopment => 'bg-hot',
            self::Planned       => 'bg-cat-blue',
            self::Deprecated    => 'bg-faint',
        };
    }

    /** @return array<int, array{value: string, label: string}> Option list for a select. */
    public static function options(): array
    {
        return array_map(
            fn (self $status) => ['value' => $status->value, 'label' => $status->label()],
            self::cases(),
        );
    }
}
