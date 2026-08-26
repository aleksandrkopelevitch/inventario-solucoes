<?php

namespace App\Enums;

/**
 * Lifecycle of a CATI submission. Everything up to `Submitted` happens in this
 * app; the three outcomes are recorded after the committee deliberates.
 *
 * `ApprovedWithConditions` is a real outcome here, not a courtesy: a condition
 * is something the committee wants tracked afterwards, which is exactly what a
 * ticket attachment can't do and a record can.
 */
enum SubmissionStatus: string
{
    case Draft = 'draft';
    case InReview = 'in_review';
    case Submitted = 'submitted';
    case Approved = 'approved';
    case ApprovedWithConditions = 'approved_with_conditions';
    case Rejected = 'rejected';
    case Withdrawn = 'withdrawn';

    public function label(): string
    {
        return match ($this) {
            self::Draft                  => 'Rascunho',
            self::InReview               => 'Em revisão',
            self::Submitted              => 'Submetida',
            self::Approved               => 'Aprovada',
            self::ApprovedWithConditions => 'Aprovada com ressalvas',
            self::Rejected               => 'Reprovada',
            self::Withdrawn              => 'Retirada',
        };
    }

    /**
     * Soft badge, in the same `soft/ink/line` shape as every other metadata
     * badge in the app.
     *
     * The class strings MUST stay literal and this file MUST stay listed in
     * `resources/css/app.css`'s `@source` block — same contract as
     * `App\Support\CategoryPalette` and `App\Enums\DiagramStatus`. A
     * concatenated `bg-cat-{tone}-soft` would never be seen by Tailwind's JIT
     * and the badge would render uncolored.
     */
    public function badgeClass(): string
    {
        return match ($this) {
            self::Draft                  => 'bg-cat-slate-soft text-cat-slate-ink ring-1 ring-cat-slate-line',
            self::InReview               => 'bg-cat-blue-soft text-cat-blue-ink ring-1 ring-cat-blue-line',
            self::Submitted              => 'bg-cat-indigo-soft text-cat-indigo-ink ring-1 ring-cat-indigo-line',
            self::Approved               => 'bg-cat-emerald-soft text-cat-emerald-ink ring-1 ring-cat-emerald-line',
            self::ApprovedWithConditions => 'bg-cat-amber-soft text-cat-amber-ink ring-1 ring-cat-amber-line',
            self::Rejected               => 'bg-cat-rose-soft text-cat-rose-ink ring-1 ring-cat-rose-line',
            self::Withdrawn              => 'bg-raised text-muted ring-1 ring-line',
        };
    }

    /** The same tone as a solid dot — the quick visual anchor on a list row. */
    public function dotClass(): string
    {
        return match ($this) {
            self::Draft                  => 'bg-cat-slate',
            self::InReview               => 'bg-cat-blue',
            self::Submitted              => 'bg-cat-indigo',
            self::Approved               => 'bg-cat-emerald',
            self::ApprovedWithConditions => 'bg-cat-amber',
            self::Rejected               => 'bg-cat-rose',
            self::Withdrawn              => 'bg-faint',
        };
    }

    /**
     * Token safe to put in a `data-*` attribute that a Tailwind arbitrary
     * variant reads (`group-data-[status=in-review]:…`).
     *
     * Tailwind turns `_` into a SPACE inside an arbitrary variant's value, so
     * `group-data-[status=in_review]` compiles to a selector matching
     * `status="in review"` and silently never fires. Never interpolate
     * `->value` straight into one of those.
     */
    public function slug(): string
    {
        return str_replace('_', '-', $this->value);
    }

    /** Whether the committee has already ruled on it — the record stops being editable as a proposal. */
    public function isDecided(): bool
    {
        return in_array($this, [self::Approved, self::ApprovedWithConditions, self::Rejected], true);
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
