<?php

namespace App\Enums;

/**
 * How a submission stands against one corporate standard.
 *
 * The point of grading rather than listing is that the committee should only
 * have to argue about the EXCEPTIONS. Anything the record can answer for itself
 * — the target cloud, the criticality, whether observability is even mentioned
 * — is answered before the meeting starts.
 */
enum ConformanceVerdict: string
{
    /** Meets the standard, as far as the record can tell. */
    case Ok = 'ok';

    /** Not stated. Not a breach — but the committee will ask. */
    case Attention = 'attention';

    /** Departs from a corporate standard, and needs an argument. */
    case Violation = 'violation';

    /** Nothing on the record to judge by — usually a missing catalog field. */
    case Unknown = 'unknown';

    public function label(): string
    {
        return match ($this) {
            self::Ok        => 'Aderente',
            self::Attention => 'Não informado',
            self::Violation => 'Exceção ao padrão',
            self::Unknown   => 'Sem dados',
        };
    }

    /** Literal classes — see the note on SubmissionStatus::badgeClass(). */
    public function badgeClass(): string
    {
        return match ($this) {
            self::Ok        => 'bg-cat-emerald-soft text-cat-emerald-ink ring-1 ring-cat-emerald-line',
            self::Attention => 'bg-cat-amber-soft text-cat-amber-ink ring-1 ring-cat-amber-line',
            self::Violation => 'bg-hot-soft text-hot ring-1 ring-hot-line',
            self::Unknown   => 'bg-raised text-muted ring-1 ring-line',
        };
    }

    public function dotClass(): string
    {
        return match ($this) {
            self::Ok        => 'bg-cat-emerald',
            self::Attention => 'bg-cat-amber',
            self::Violation => 'bg-hot',
            self::Unknown   => 'bg-faint',
        };
    }

    /** Whether the committee needs to spend time on it. */
    public function needsArgument(): bool
    {
        return $this !== self::Ok;
    }
}
