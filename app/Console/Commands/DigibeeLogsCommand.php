<?php

namespace App\Console\Commands;

use App\Exceptions\DigibeeApiException;
use App\Support\Digibee\Monitor\DigibeeMonitorClient;
use App\Support\Digibee\Monitor\ExecutionCollector;
use App\Support\Digibee\Monitor\LogRedactor;
use App\Support\Digibee\Monitor\MonitorSession;
use App\Support\Digibee\Monitor\MonitorTime;
use Carbon\CarbonImmutable;
use Illuminate\Console\Command;
use Illuminate\Http\Client\ConnectionException;
use Symfony\Component\Console\Formatter\OutputFormatter;
use Symfony\Component\Console\Output\OutputInterface;

/**
 * Reads Digibee execution logs the way the Monitor screen does: list the
 * executions in a window (or search the log lines of all of them), then pull
 * every log line of the one that matters.
 *
 * Everything printed passes through LogRedactor first. Production logs here
 * carry consumer credentials and customer data (see that class), and this
 * output is meant to be pasted into a chat or read by an agent.
 *
 * Workstation only: it replays a person's web session, captured by
 * `digibee:monitor:login`.
 */
class DigibeeLogsCommand extends Command
{
    protected $signature = 'digibee:logs
        {--environment=prod : test or prod}
        {--key= : Pipeline execution key — print every log line of that execution}
        {--since=1h : Start of the window: how long ago (15m, 6h, 2d) or a local date/time ("2026-11-03 14:00", "03/11 14:00"). Logs live 10 days in prod, 3 in test}
        {--until= : End of the window, same forms as --since (default: now)}
        {--pipeline= : Only executions of this pipeline (exact name, filtered by the platform)}
        {--search= : Only executions whose payloads contain this text (an order id, a SKU…)}
        {--lines : List log lines instead of executions (the Pipeline Logs tab)}
        {--message= : Only log lines containing this text — implies --lines}
        {--limit=50 : Executions or lines per page (the platform caps it at 100)}
        {--pages=1 : Follow the next-page cursor this many times (at most 20)}
        {--json : Print the (redacted) JSON as returned instead of a table}
        {--collect= : Open EVERY matched execution and write one redacted JSON report to this file (relative = under storage/app/private/digibee-monitor/reports)}';

    protected $description = 'Read Digibee executions and execution logs (redacted) through the Monitor routes';

    /** Executions one --collect opens: ~3.5 minutes (500 took 1m47s on 2026-11-04). */
    private const MAX_COLLECT = 1000;

    /** The window every search in this run uses — see handle(). */
    private CarbonImmutable $from;

    private CarbonImmutable $to;

    public function handle(LogRedactor $redactor): int
    {
        if (app()->isProduction()) {
            $this->components->error(DigibeeApiException::refusedMonitorOnServer()->getMessage());

            return self::FAILURE;
        }

        $session = MonitorSession::fromFile((string) config('services.digibee.monitor.session_path'));

        if ($session === null || $session->expired()) {
            $this->components->error(DigibeeApiException::monitorSessionMissing()->getMessage());

            return self::FAILURE;
        }

        $lines = $this->option('lines') || filled($this->option('message'));

        // The two searches are over different things — payloads belong to an
        // execution, messages to a log line — and neither route takes the
        // other's key.
        if ($lines && filled($this->option('search'))) {
            $this->components->error('--search looks in execution payloads and --message/--lines in log lines — use one or the other.');

            return self::FAILURE;
        }

        // Read once, before any request: every page of a search and the
        // report that describes it must agree on the same two instants.
        try {
            [$this->from, $this->to] = MonitorTime::window((string) $this->option('since'), $this->option('until'));
        } catch (\InvalidArgumentException $e) {
            $this->components->error($e->getMessage());

            return self::FAILURE;
        }

        $client = new DigibeeMonitorClient($session);
        $environment = (string) $this->option('environment');

        try {
            return match (true) {
                filled($this->option('key'))     => $this->printLogs($client, $redactor, $environment, (string) $this->option('key')),
                filled($this->option('collect')) => $this->collect($client, $redactor, $environment, $lines),
                $lines                           => $this->printLines($client, $redactor, $environment),
                default                          => $this->printExecutions($client, $redactor, $environment),
            };
        } catch (DigibeeApiException|ConnectionException $e) {
            // Whatever the failure, the cheap first fix is signing in again: a
            // session that died mid-day does not always answer 401.
            $this->components->error($e->getMessage());
            $this->line('<fg=gray>If Digibee cannot be reached, sign in again first: php artisan digibee:monitor:login</>');

            return self::FAILURE;
        }
    }

    private function printLogs(DigibeeMonitorClient $client, LogRedactor $redactor, string $environment, string $key): int
    {
        // The export comes newest first; an execution reads top to bottom.
        $rows = array_reverse($client->executionLogs($environment, $key));

        if ($this->option('json')) {
            $this->raw($redactor->json($rows));

            return self::SUCCESS;
        }

        if ($rows === []) {
            $this->components->warn("No log lines for execution {$key} in {$environment} (expired, or a wrong key).");

            return self::SUCCESS;
        }

        // Measured columns (2026-11-04): timestamp, realm, pipelineKey,
        // pipelineName, logLevel, logMessage. The middle three repeat on every
        // line, so they are said once in the header instead.
        $first = $rows[0];
        $this->line('<options=bold>' . ($first['pipelineName'] ?? '?') . "</> · {$environment} · {$key}");
        $this->newLine();

        foreach ($rows as $row) {
            $this->line(MonitorTime::short($row['timestamp'] ?? null) . ' ' . $this->levelTag((string) ($row['logLevel'] ?? '')) . ' ' . OutputFormatter::escape($redactor->redact(trim($row['logMessage'] ?? ''))));
        }

        $this->newLine();
        $this->line('<fg=gray>' . count($rows) . ' line(s)</>');

        return self::SUCCESS;
    }

    /**
     * Everything a report needs in one file: the search (executions by
     * payload, or log lines by message), then EVERY matched execution opened
     * — its whole log, and for an execution search its payloads too.
     *
     * It goes to a file, not the terminal, because a few hundred executions
     * are megabytes: the file is read with jq/grep, a terminal or a chat
     * would only be flooded. It stays on the workstation, under the
     * git-ignored private disk, owner-only — redacted, but still production.
     */
    private function collect(DigibeeMonitorClient $client, LogRedactor $redactor, string $environment, bool $lines): int
    {
        $collector = new ExecutionCollector($client);

        $search = $collector->find(
            $environment,
            $this->from,
            $this->to,
            $this->option('pipeline'),
            $lines ? $this->option('message') : $this->option('search'),
            $lines,
            (int) $this->option('limit'),
            (int) $this->option('pages'),
        );

        $toOpen = min(count($search['found']), self::MAX_COLLECT);
        $this->components->info("{$search['matches']} match(es) in " . count($search['found']) . " execution(s) — opening {$toOpen}…");

        $opened = $collector->open($environment, $search['found'], self::MAX_COLLECT);

        $report = [
            'query' => array_filter([
                'environment' => $environment,
                'searched'    => $lines ? 'log lines' : 'executions',
                'pipeline'    => $this->option('pipeline'),
                'message'     => $lines ? $this->option('message') : null,
                'search'      => $lines ? null : $this->option('search'),
                'from'        => MonitorTime::iso($this->from->toIso8601String()),
                'to'          => MonitorTime::iso($this->to->toIso8601String()),
                'timezone'    => MonitorTime::timezone(),
            ], fn ($value) => $value !== null && $value !== ''),
            'matches'          => $search['matches'],
            'platform_total'   => $search['total'],
            'more_in_window'   => $search['has_next'],
            'executions_found' => count($search['found']),
            'not_opened'       => $opened['not_opened'],
            'unreadable'       => $opened['unreadable'],
            'executions'       => $opened['executions'],
        ];

        $path = self::reportPath((string) $this->option('collect'));
        file_put_contents($path, $redactor->json($report));
        chmod($path, 0600);

        $this->summarise($report, $path);

        return self::SUCCESS;
    }

    /** Where a report lands: under the private disk unless the path is absolute. */
    public static function reportPath(string $name): string
    {
        $path = str_starts_with($name, '/')
            ? $name
            : storage_path('app/private/digibee-monitor/reports/' . $name);

        $directory = dirname($path);

        if (! is_dir($directory)) {
            mkdir($directory, 0700, true);
        }

        return $path;
    }

    /** @param  array<string, mixed>  $report */
    private function summarise(array $report, string $path): void
    {
        $byPipeline = [];

        foreach ($report['executions'] as $execution) {
            $row = &$byPipeline[$execution['pipeline'] ?? '?'];
            $row ??= ['executions' => 0, 'with errors' => 0, 'first' => null, 'last' => null];
            $row['executions']++;
            $row['with errors'] += ($execution['levels']['ERROR'] ?? 0) > 0 ? 1 : 0;
            $row['first'] ??= $execution['started_at'];
            $row['last'] = $execution['started_at'] ?? $row['last'];
            unset($row);
        }

        if ($byPipeline !== []) {
            $this->table(['pipeline', 'executions', 'with errors', 'first', 'last'], array_map(
                fn (string $pipeline, array $row) => [$pipeline, ...array_values($row)],
                array_keys($byPipeline),
                $byPipeline,
            ));
        }

        $notes = array_filter([
            $report['more_in_window'] ? 'more matches in this window — raise --pages or narrow the search' : null,
            $report['not_opened'] !== [] ? count($report['not_opened']) . ' execution(s) past the ' . self::MAX_COLLECT . ' cap not opened (listed in not_opened)' : null,
            $report['unreadable'] !== [] ? count($report['unreadable']) . ' execution(s) the platform would not export (listed in unreadable)' : null,
        ]);

        foreach ($notes as $note) {
            $this->components->warn($note);
        }

        $this->components->info("Report written to {$path}");
    }

    private function printExecutions(DigibeeMonitorClient $client, LogRedactor $redactor, string $environment): int
    {
        // Measured shape (2026-11-04): `data` is a list of OpenTelemetry-style
        // log records, one per completed execution, the useful fields flat
        // under `attributes` with dotted names.
        [$executions, $total, $hasNext] = ExecutionCollector::paged(fn (?string $cursor) => $client->executions(
            $environment,
            $this->from,
            $this->to,
            (int) $this->option('limit'),
            $this->option('pipeline'),
            $this->option('search'),
            $cursor,
        ), (int) $this->option('pages'));

        if ($this->option('json')) {
            $this->raw($redactor->json($executions));

            return self::SUCCESS;
        }

        if ($executions === []) {
            $this->components->warn("No matching executions in {$environment} between " . MonitorTime::display($this->from) . ' and ' . MonitorTime::display($this->to) . '.');

            return self::SUCCESS;
        }

        // The payloads stay out of the table — they are what `--json` and
        // `--key` are for; a row is for spotting the execution worth opening.
        $this->table(['when', 'pipeline', 'status', 'ms', 'execution key'], array_map(function (array $execution) {
            $attributes = $execution['attributes'] ?? [];

            return [
                MonitorTime::short($execution['timestamp'] ?? null),
                ($attributes['pipeline.name'] ?? '?') . ' ' . ($attributes['pipeline.version'] ?? ''),
                $this->status($attributes),
                $attributes['execution.elapsed_time_ms'] ?? '',
                $attributes['pipeline.key'] ?? '',
            ];
        }, $executions));

        $this->footer(count($executions), $total, $hasNext, 'execution(s)', '--search');
        $this->line('<fg=gray>Open one with: php artisan digibee:logs --environment=' . $environment . ' --key=<execution key></>');

        return self::SUCCESS;
    }

    /**
     * The Pipeline Logs tab: log lines of every execution in the window,
     * searched by their text — the way to find an order id that only ever
     * appeared in a Log connector's message, not in a payload.
     */
    private function printLines(DigibeeMonitorClient $client, LogRedactor $redactor, string $environment): int
    {
        // Measured shape (2026-11-04): the same records as the executions
        // route, one per log line, the text in `body` and the level in
        // `severityText`. `attributes.pipeline.key` is the execution — absent
        // on the platform's own lines (a consumer draining on SIGTERM).
        [$lines, $total, $hasNext] = ExecutionCollector::paged(fn (?string $cursor) => $client->pipelineLogs(
            $environment,
            $this->from,
            $this->to,
            (int) $this->option('limit'),
            $this->option('pipeline'),
            $this->option('message'),
            $cursor,
        ), (int) $this->option('pages'));

        if ($this->option('json')) {
            $this->raw($redactor->json($lines));

            return self::SUCCESS;
        }

        if ($lines === []) {
            $this->components->warn("No matching log lines in {$environment} between " . MonitorTime::display($this->from) . ' and ' . MonitorTime::display($this->to) . '.');

            return self::SUCCESS;
        }

        foreach ($lines as $line) {
            $attributes = $line['attributes'] ?? [];

            $this->line(implode(' ', array_filter([
                MonitorTime::short($line['timestamp'] ?? null),
                $this->levelTag((string) ($line['severityText'] ?? '')),
                OutputFormatter::escape(trim(($attributes['pipeline.name'] ?? '?') . ' ' . ($attributes['pipeline.version'] ?? ''))),
                '<fg=gray>' . ($attributes['pipeline.key'] ?? '-') . '</>',
                OutputFormatter::escape($redactor->redact(trim((string) ($line['body'] ?? '')))),
            ])));
        }

        $this->newLine();
        $this->footer(count($lines), $total, $hasNext, 'line(s)', '--message');
        $this->line('<fg=gray>Read the whole execution with: php artisan digibee:logs --environment=' . $environment . ' --key=<execution key></>');

        return self::SUCCESS;
    }

    private function footer(int $shown, ?int $total, bool $hasNext, string $noun, string $searchOption): void
    {
        // Both routes answer a total of exactly 10000 for anything bigger — a
        // count capped by the search engine, not a count.
        $of = match (true) {
            $total === null                            => '',
            $total >= ExecutionCollector::CAPPED_TOTAL => ' of ' . ExecutionCollector::CAPPED_TOTAL . '+',
            default                                    => " of {$total}",
        };

        $more = $hasNext ? " — more in this window: raise --pages or narrow --since/--pipeline/{$searchOption}" : '';

        $this->line("<fg=gray>{$shown}{$of} {$noun}{$more}</>");
    }

    private function levelTag(string $level): string
    {
        return match (strtoupper($level)) {
            'ERROR', 'FATAL'  => "<fg=red>{$level}</>",
            'WARN', 'WARNING' => "<fg=yellow>{$level}</>",
            default           => $level,
        };
    }

    /**
     * Payloads are full of XML, and the console formatter reads every `<…>` as
     * a style tag: slow on half a megabyte, and wrong when a payload happens to
     * contain `<info>`. What came from the platform is written as-is.
     */
    private function raw(string $text): void
    {
        $this->output->writeln($text, OutputInterface::OUTPUT_RAW);
    }

    /** The response code the pipeline answered, for a table cell. */
    private function status(array $attributes): string
    {
        $code = ExecutionCollector::statusCode($attributes);

        if ($code === null) {
            return (string) ($attributes['log.type'] ?? '?');
        }

        return $code >= 400 ? "<fg=red>{$code}</>" : (string) $code;
    }
}
