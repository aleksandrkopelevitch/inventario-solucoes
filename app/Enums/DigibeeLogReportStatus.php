<?php

namespace App\Enums;

/** Where a collected Digibee log report stands (`DigibeeLogReport`). */
enum DigibeeLogReportStatus: string
{
    case Pending = 'pending';
    case Running = 'running';
    case Done = 'done';
    case Failed = 'failed';

    public function settled(): bool
    {
        return $this === self::Done || $this === self::Failed;
    }

    public function label(): string
    {
        return match ($this) {
            self::Pending => 'Na fila',
            self::Running => 'Coletando',
            self::Done    => 'Pronto',
            self::Failed  => 'Falhou',
        };
    }

    public function badgeClass(): string
    {
        return match ($this) {
            self::Pending, self::Running => 'bg-cat-amber-soft text-cat-amber-ink ring-1 ring-cat-amber-line',
            self::Done                   => 'bg-cat-emerald-soft text-cat-emerald-ink ring-1 ring-cat-emerald-line',
            self::Failed                 => 'bg-hot-soft text-hot ring-1 ring-hot-line',
        };
    }
}
