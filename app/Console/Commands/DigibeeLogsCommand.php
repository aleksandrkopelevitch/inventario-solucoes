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
        {--json : Print the (redacted) JSON as returned instead of a table}';

    protected $description = 'Read Digibee executions and execution logs (redacted) through the Monitor routes';

    /** 20 pages of 100 — past that, a narrower window or filter is the better question. */
    private const MAX_PAGES = 20;

    /** The log search's `total` never goes past this (measured 2026-11-04). */
    private const CAPPED_TOTAL = 10000;

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
                filled($this->option('key')) => $this->printLogs($client, $redactor, $environment, (string) $this->option('key')),
                $lines                       => $this->printLines($client, $redactor, $environment),
                default                      => $this->printExecutions($client, $redactor, $environment),
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
            $this->raw($redactor->redact(json_encode($rows, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE)));

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
            $this->raw($redactor->redact(json_encode($executions, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE)));

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
            $this->raw($redactor->redact(json_encode($lines, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE)));

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
        $response = json_decode((string) ($attributes['payload.response'] ?? ''), true);
        $code = is_array($response) ? ($response['code'] ?? null) : null;

        if ($code === null) {
            return (string) ($attributes['log.type'] ?? '?');
        }

        return (int) $code >= 400 ? "<fg=red>{$code}</>" : (string) $code;
    }

    private function localTime(mixed $timestamp): string
    {
        return is_string($timestamp)
            ? CarbonImmutable::parse($timestamp)->setTimezone((string) config('app.display_timezone'))->format('d/m H:i:s')
            : '';
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
