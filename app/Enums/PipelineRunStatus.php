<?php

namespace App\Enums;

enum PipelineRunStatus: string
{
    /** Queued, not yet picked up by a worker. */
    case Pending = 'pending';

    /** A worker has it, and rounds are being appended as they finish. */
    case Running = 'running';

    /** The loop returned a verdict — which may well be a refusal. */
    case Done = 'done';

    /**
     * The job threw, or a worker died holding it.
     *
     * Deliberately not "the pipeline failed": a run that ends `Done` with a
     * `StillFailing` verdict did its whole job. This case is about the RUN,
     * and collapsing the two would report a broken queue as a broken pipeline.
     */
    case Failed = 'failed';

    public function settled(): bool
    {
        return in_array($this, [self::Done, self::Failed], true);
    }

    public function label(): string
    {
        return match ($this) {
            self::Pending => 'na fila',
            self::Running => 'rodando',
            self::Done    => 'concluída',
            self::Failed  => 'falhou',
        };
    }
}
