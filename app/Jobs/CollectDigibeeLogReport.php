<?php

namespace App\Jobs;

use App\Enums\DigibeeLogReportStatus;
use App\Models\DigibeeLogReport;
use App\Support\Digibee\Monitor\DigibeeMonitorClient;
use App\Support\Digibee\Monitor\LogRedactor;
use App\Support\Digibee\Monitor\LogSearch;
use App\Support\Digibee\Monitor\ServerSession;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Throwable;

/**
 * Collects a report the "Logs Digibee" screen asked for: finds every match,
 * opens up to `MAX_OPEN` executions, and stores the result redacted.
 *
 * Queued because a report of a few hundred executions takes minutes (500 took
 * 1m47s) — a request could not hold it. The screen polls the report's status
 * through marker-poll.js.
 */
class CollectDigibeeLogReport implements ShouldQueue
{
    use Queueable;

    /** The same ceiling as `--collect` and the local MCP search. */
    public const MAX_OPEN = 1000;

    /**
     * 1000 executions at ~5/s is ~3.5 min, plus the find; inside the worker's
     * `--timeout=660` and `retry_after` (900).
     */
    public int $timeout = 540;

    /** A report is asked for again by a person, never retried behind them. */
    public int $tries = 1;

    public bool $failOnTimeout = true;

    public function __construct(public readonly DigibeeLogReport $report) {}

    public function handle(LogRedactor $redactor): void
    {
        $session = (new ServerSession)->session();

        if ($session === null) {
            $this->report->update(['status' => DigibeeLogReportStatus::Failed, 'error' => 'A sessão da Digibee do iSol expirou. Os administradores foram avisados.']);

            return;
        }

        $this->report->update(['status' => DigibeeLogReportStatus::Running]);

        $collected = (new LogSearch(new DigibeeMonitorClient($session)))->collect($this->report->logQuery(), self::MAX_OPEN);

        $this->report->complete($redactor->json($collected), $collected['executions_found'], count($collected['executions']));
    }

    public function failed(?Throwable $exception): void
    {
        $this->report->update([
            'status' => DigibeeLogReportStatus::Failed,
            'error'  => 'A coleta parou com um erro ao falar com a Digibee. Tente de novo; se repetir, a sessão pode ter expirado.',
        ]);
    }
}
