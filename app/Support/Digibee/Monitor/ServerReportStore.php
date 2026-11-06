<?php

namespace App\Support\Digibee\Monitor;

use App\Enums\DigibeeLogReportStatus;
use App\Mcp\Actor;
use App\Mcp\InvalidToolArguments;
use App\Models\DigibeeLogReport;
use App\Support\Digibee\Monitor\Mcp\ReportStore;
use Illuminate\Support\Str;

/**
 * Reports the iSol `/mcp` search collects: a `DigibeeLogReport` row naming who
 * asked, the redacted executions in a private file. Named by uuid — the same
 * report then opens on the "Logs Digibee" screen.
 */
final class ServerReportStore implements ReportStore
{
    /** `$actor` is who the saved report is attributed to; describing the tools needs none. */
    public function __construct(private readonly ?Actor $actor = null) {}

    public function save(string $redactedJson, LogQuery $query, array $summary): string
    {
        $actor = $this->actor ?? throw new \LogicException('A report is saved on behalf of an actor.');
        $report = DigibeeLogReport::begin($query, 'mcp', DigibeeLogReportStatus::Running, $actor->user, $actor->token);
        $report->complete($redactedJson, $summary['executions_found'], $summary['executions_opened']);

        return $report->uuid;
    }

    public function load(string $name): array
    {
        if (! Str::isUuid($name)) {
            throw new InvalidToolArguments('report deve ser o identificador devolvido por search_digibee_logs.');
        }

        $report = DigibeeLogReport::query()->where('uuid', $name)->first();

        if ($report === null || $report->status !== DigibeeLogReportStatus::Done || ($contents = $report->contents()) === null) {
            throw new \InvalidArgumentException("Relatório \"{$name}\" não encontrado (ou ainda não terminou). Relatórios ficam " . DigibeeLogReport::RETENTION_DAYS . ' dias no iSol.');
        }

        return $contents;
    }

    public function where(): string
    {
        return 'no iSol (também aparece na tela "Logs Digibee")';
    }
}
