<?php

namespace App\Enums;

/**
 * Result of pulling text out of one source.
 *
 * `Skipped` is NOT a failure and must not be shown as one: a PDF or an image
 * is deliberately left unextracted because it goes to the model as a native
 * attachment (Laravel\Ai\Files\LocalDocument / LocalImage), the same
 * partitioning `App\Services\Documentation\ContextDocumentResolver` already
 * does for a Solution's context documents. Reporting it as an error would
 * teach users to re-upload files that are working fine.
 */
enum ContextExtractionState: string
{
    case Pending = 'pending';
    case Done = 'done';
    case Skipped = 'skipped';
    case Failed = 'failed';

    public function label(): string
    {
        return match ($this) {
            self::Pending => 'Aguardando leitura',
            self::Done    => 'Texto extraído',
            self::Skipped => 'Vai como anexo',
            self::Failed  => 'Não foi possível ler',
        };
    }

    /** Literal classes — see the note on SubmissionStatus::badgeClass(). */
    public function badgeClass(): string
    {
        return match ($this) {
            self::Pending => 'bg-raised text-muted ring-1 ring-line',
            self::Done    => 'bg-cat-emerald-soft text-cat-emerald-ink ring-1 ring-cat-emerald-line',
            self::Skipped => 'bg-cat-blue-soft text-cat-blue-ink ring-1 ring-cat-blue-line',
            self::Failed  => 'bg-hot-soft text-hot ring-1 ring-hot-line',
        };
    }
}
