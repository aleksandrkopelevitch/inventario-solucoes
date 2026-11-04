<?php

namespace App\Console\Commands;

use App\Exceptions\DigibeeApiException;
use App\Support\Digibee\Monitor\DigibeeMonitorClient;
use App\Support\Digibee\Monitor\LogRedactor;
use App\Support\Digibee\Monitor\MonitorSession;
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
        {--since=1h : Window for the execution list (e.g. 15m, 1h, 1d; logs live 10 days in prod, 3 in test)}
        {--pipeline= : Only executions of this pipeline (exact name, filtered by the platform)}
        {--search= : Only executions whose payloads contain this text (an order id, a SKU…)}
        {--lines : List log lines instead of executions (the Pipeline Logs tab)}
        {--message= : Only log lines containing this text — implies --lines}
        {--limit=50 : Executions or lines per page (the platform caps it at 100)}
        {--pages=1 : Follow the next-page cursor this many times (at most 20)}
        {--json : Print the (redacted) JSON as returned instead of a table}
        {--collect= : Open EVERY matched execution and write one redacted JSON report to this file (relative = under storage/app/private/digibee-monitor/reports)}';

    protected $description = 'Read Digibee executions and execution logs (redacted) through the Monitor routes';

    /** 20 pages of 100 — past that, a narrower window or filter is the better question. */
    private const MAX_PAGES = 20;

    /** The log search's `total` never goes past this (measured 2026-11-04). */
    private const CAPPED_TOTAL = 10000;

    /** Executions one --collect opens: ~3.5 minutes (500 took 1m47s on 2026-11-04). */
    private const MAX_COLLECT = 1000;

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
            $this->components->error($e->getMessage());

            return self::FAILURE;
        }
    }

    private function printLogs(DigibeeMonitorClient $client, LogRedactor $redactor, string $environment, string $key): int
    {
        // The export comes newest first; an execution reads top to bottom.
        $rows = array_reverse($client->executionLogs($environment, $key));

        if ($this->option('json')) {
            $this->raw($this->encodeRedacted($redactor, $rows));

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
            $this->line($this->localTime($row['timestamp'] ?? null) . ' ' . $this->levelTag((string) ($row['logLevel'] ?? '')) . ' ' . OutputFormatter::escape($redactor->redact(trim($row['logMessage'] ?? ''))));
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
        $since = (string) $this->option('since');
        $to = CarbonImmutable::now();
        $from = $to->sub($this->window($since));

        [$records, $total, $hasNext] = $this->paged(fn (CarbonImmutable $from, CarbonImmutable $to, ?string $cursor) => $lines
            ? $client->pipelineLogs($environment, $from, $to, (int) $this->option('limit'), $this->option('pipeline'), $this->option('message'), $cursor)
            : $client->executions($environment, $from, $to, (int) $this->option('limit'), $this->option('pipeline'), $this->option('search'), $cursor));

        // One entry per execution, in the order the search found them; a
        // log-line search finds the same execution once per matching line.
        $found = [];

        foreach ($records as $record) {
            $attributes = $record['attributes'] ?? [];
            $key = $attributes['pipeline.key'] ?? null;

            if (! is_string($key) || $key === '') {
                continue;
            }

            $found[$key] ??= [
                'key'      => $key,
                'pipeline' => $attributes['pipeline.name'] ?? null,
                'version'  => $attributes['pipeline.version'] ?? null,
            ] + ($lines ? ['matched_lines' => []] : [
                'status'           => $this->statusCode($attributes),
                'elapsed_ms'       => $attributes['execution.elapsed_time_ms'] ?? null,
                'payload_request'  => $attributes['payload.request'] ?? null,
                'payload_response' => $attributes['payload.response'] ?? null,
            ]);

            if ($lines) {
                $found[$key]['matched_lines'][] = (string) ($record['body'] ?? '');
            }
        }

        $notOpened = array_slice(array_keys($found), self::MAX_COLLECT);
        $keys = array_slice(array_keys($found), 0, self::MAX_COLLECT);

        $this->components->info(count($records) . ' match(es) in ' . count($found) . ' execution(s) — opening ' . count($keys) . '…');

        $logs = $keys === [] ? [] : $client->executionLogsMany($environment, $keys);

        $executions = [];

        foreach ($keys as $key) {
            // The export comes newest first; an execution reads top to bottom.
            $rows = $logs[$key] === null ? null : array_reverse($logs[$key]);
            $levels = $rows === null ? [] : array_count_values(array_map(fn (array $row) => strtoupper($row['logLevel'] ?? '?'), $rows));

            $executions[] = $found[$key] + [
                'started_at' => $rows ? $this->isoLocal($rows[0]['timestamp'] ?? null) : null,
                'ended_at'   => $rows ? $this->isoLocal($rows[count($rows) - 1]['timestamp'] ?? null) : null,
                'levels'     => $levels,
                'unreadable' => $rows === null,
                'lines'      => array_map(fn (array $row) => [
                    'at'      => $this->isoLocal($row['timestamp'] ?? null),
                    'level'   => $row['logLevel'] ?? null,
                    'message' => $row['logMessage'] ?? null,
                ], $rows ?? []),
            ];
        }

        // Chronological: a report reads as what happened, in order.
        usort($executions, fn (array $a, array $b) => strcmp((string) $a['started_at'], (string) $b['started_at']));

        $report = [
            'query' => array_filter([
                'environment' => $environment,
                'searched'    => $lines ? 'log lines' : 'executions',
                'pipeline'    => $this->option('pipeline'),
                'message'     => $lines ? $this->option('message') : null,
                'search'      => $lines ? null : $this->option('search'),
                'since'       => $since,
                'from'        => $this->isoLocal($from->toIso8601String()),
                'to'          => $this->isoLocal($to->toIso8601String()),
                'timezone'    => (string) config('app.display_timezone'),
            ], fn ($value) => $value !== null && $value !== ''),
            'matches'          => count($records),
            'platform_total'   => $total,
            'more_in_window'   => $hasNext,
            'executions_found' => count($found),
            'not_opened'       => $notOpened,
            'unreadable'       => array_values(array_keys(array_filter($logs, fn ($rows) => $rows === null))),
            'executions'       => $executions,
        ];

        $path = $this->reportPath((string) $this->option('collect'));
        file_put_contents($path, $this->encodeRedacted($redactor, $report));
        chmod($path, 0600);

        $this->summarise($report, $path);

        return self::SUCCESS;
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

    private function reportPath(string $option): string
    {
        $path = str_starts_with($option, '/')
            ? $option
            : storage_path('app/private/digibee-monitor/reports/' . $option);

        $directory = dirname($path);

        if (! is_dir($directory)) {
            mkdir($directory, 0700, true);
        }

        return $path;
    }

    /**
     * Redacted twice on purpose. Each string first, at its own level of
     * escaping — a payload is JSON in a string, and its body is often JSON
     * in a string again, which encoding once more would push one escape
     * deeper than the key rules reach. Then the encoded whole, for anything
     * the structure itself carries.
     */
    private function encodeRedacted(LogRedactor $redactor, mixed $data): string
    {
        array_walk_recursive($data, function (mixed &$value) use ($redactor) {
            if (is_string($value)) {
                $value = $redactor->redact($value);
            }
        });

        return $redactor->redact((string) json_encode($data, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE));
    }

    private function printExecutions(DigibeeMonitorClient $client, LogRedactor $redactor, string $environment): int
    {
        // Measured shape (2026-11-04): `data` is a list of OpenTelemetry-style
        // log records, one per completed execution, the useful fields flat
        // under `attributes` with dotted names.
        [$executions, $total, $hasNext] = $this->paged(fn (CarbonImmutable $from, CarbonImmutable $to, ?string $cursor) => $client->executions(
            $environment,
            $from,
            $to,
            (int) $this->option('limit'),
            $this->option('pipeline'),
            $this->option('search'),
            $cursor,
        ));

        if ($this->option('json')) {
            $this->raw($this->encodeRedacted($redactor, $executions));

            return self::SUCCESS;
        }

        if ($executions === []) {
            $this->components->warn("No matching executions in {$environment} in the last {$this->option('since')}.");

            return self::SUCCESS;
        }

        // The payloads stay out of the table — they are what `--json` and
        // `--key` are for; a row is for spotting the execution worth opening.
        $this->table(['when', 'pipeline', 'status', 'ms', 'execution key'], array_map(function (array $execution) {
            $attributes = $execution['attributes'] ?? [];

            return [
                $this->localTime($execution['timestamp'] ?? null),
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
        [$lines, $total, $hasNext] = $this->paged(fn (CarbonImmutable $from, CarbonImmutable $to, ?string $cursor) => $client->pipelineLogs(
            $environment,
            $from,
            $to,
            (int) $this->option('limit'),
            $this->option('pipeline'),
            $this->option('message'),
            $cursor,
        ));

        if ($this->option('json')) {
            $this->raw($this->encodeRedacted($redactor, $lines));

            return self::SUCCESS;
        }

        if ($lines === []) {
            $this->components->warn("No matching log lines in {$environment} in the last {$this->option('since')}.");

            return self::SUCCESS;
        }

        foreach ($lines as $line) {
            $attributes = $line['attributes'] ?? [];

            $this->line(implode(' ', array_filter([
                $this->localTime($line['timestamp'] ?? null),
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

    /**
     * Follows the next-page cursor up to --pages times.
     *
     * The window is fixed once, before the first page: a cursor belongs to
     * the query that issued it, and a `to` that moved on every page would be
     * a different query.
     *
     * @param  callable(CarbonImmutable, CarbonImmutable, ?string): array<mixed>  $fetch
     * @return array{0: list<array<mixed>>, 1: ?int, 2: bool}
     */
    private function paged(callable $fetch): array
    {
        $to = CarbonImmutable::now();
        $from = $to->sub($this->window((string) $this->option('since')));
        $pages = max(1, min((int) $this->option('pages'), self::MAX_PAGES));

        $records = [];
        $cursor = null;
        $total = null;
        $hasNext = false;

        for ($page = 0; $page < $pages; $page++) {
            $data = $fetch($from, $to, $cursor);

            array_push($records, ...array_values(array_filter((array) ($data['data'] ?? []), 'is_array')));

            $total ??= is_int($data['total'] ?? null) ? $data['total'] : null;
            $cursor = $data['pagination']['nextCursor'] ?? null;
            $hasNext = ($data['pagination']['hasNext'] ?? false) === true && is_string($cursor) && $cursor !== '';

            if (! $hasNext) {
                break;
            }
        }

        return [$records, $total, $hasNext];
    }

    private function footer(int $shown, ?int $total, bool $hasNext, string $noun, string $searchOption): void
    {
        // The log search answers a total of exactly 10000 for anything bigger —
        // a count capped by the search engine, not a count.
        $of = match (true) {
            $total === null              => '',
            $total >= self::CAPPED_TOTAL => ' of ' . self::CAPPED_TOTAL . '+',
            default                      => " of {$total}",
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

    /** The response code the pipeline answered, read from the captured response payload. */
    private function status(array $attributes): string
    {
        $code = $this->statusCode($attributes);

        if ($code === null) {
            return (string) ($attributes['log.type'] ?? '?');
        }

        return $code >= 400 ? "<fg=red>{$code}</>" : (string) $code;
    }

    private function statusCode(array $attributes): ?int
    {
        $response = json_decode((string) ($attributes['payload.response'] ?? ''), true);
        $code = is_array($response) ? ($response['code'] ?? null) : null;

        return is_numeric($code) ? (int) $code : null;
    }

    private function localTime(mixed $timestamp): string
    {
        return is_string($timestamp)
            ? CarbonImmutable::parse($timestamp)->setTimezone((string) config('app.display_timezone'))->format('d/m H:i:s')
            : '';
    }

    /** A sortable local timestamp, for files — the terminal's `d/m H:i:s` does not sort across months. */
    private function isoLocal(mixed $timestamp): ?string
    {
        return is_string($timestamp) && $timestamp !== ''
            ? CarbonImmutable::parse($timestamp)->setTimezone((string) config('app.display_timezone'))->format('Y-m-d H:i:s.v')
            : null;
    }

    private function window(string $since): \DateInterval
    {
        if (preg_match('/^(\d+)\s*([mhd])$/', trim($since), $m) !== 1) {
            return new \DateInterval('PT1H');
        }

        return match ($m[2]) {
            'm' => new \DateInterval("PT{$m[1]}M"),
            'h' => new \DateInterval("PT{$m[1]}H"),
            'd' => new \DateInterval("P{$m[1]}D"),
        };
    }
}
