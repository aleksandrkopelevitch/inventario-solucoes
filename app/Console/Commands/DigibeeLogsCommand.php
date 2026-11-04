<?php

namespace App\Console\Commands;

use App\Exceptions\DigibeeApiException;
use App\Support\Digibee\Monitor\DigibeeMonitorClient;
use App\Support\Digibee\Monitor\LogRedactor;
use App\Support\Digibee\Monitor\MonitorSession;
use Illuminate\Console\Command;
use Illuminate\Http\Client\ConnectionException;
use Symfony\Component\Console\Formatter\OutputFormatter;
use Symfony\Component\Console\Output\OutputInterface;

/**
 * Reads Digibee execution logs the way the Monitor screen does: list the
 * executions in a window, then pull every log line of the one that matters.
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
        {--pipeline= : Only executions of this pipeline name}
        {--limit=50 : How many executions to ask for}
        {--json : Print the (redacted) JSON as returned instead of a table}';

    protected $description = 'Read Digibee executions and execution logs (redacted) through the Monitor routes';

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

        $client = new DigibeeMonitorClient($session);
        $environment = (string) $this->option('environment');

        try {
            return $this->option('key')
                ? $this->printLogs($client, $redactor, $environment, (string) $this->option('key'))
                : $this->printExecutions($client, $redactor, $environment);
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
            $level = $row['logLevel'] ?? '';
            $levelTag = match (strtoupper($level)) {
                'ERROR' => "<fg=red>{$level}</>",
                'WARN', 'WARNING' => "<fg=yellow>{$level}</>",
                default => $level,
            };

            $this->line($this->localTime($row['timestamp'] ?? null) . " {$levelTag} " . OutputFormatter::escape($redactor->redact(trim($row['logMessage'] ?? ''))));
        }

        $this->newLine();
        $this->line('<fg=gray>' . count($rows) . ' line(s)</>');

        return self::SUCCESS;
    }

    private function printExecutions(DigibeeMonitorClient $client, LogRedactor $redactor, string $environment): int
    {
        $to = now();
        $from = $to->copy()->sub($this->window((string) $this->option('since')));
        $data = $client->executions($environment, $from, $to, (int) $this->option('limit'));

        // Measured shape (2026-11-04): `data` is a list of OpenTelemetry-style
        // log records, one per completed execution, the useful fields flat
        // under `attributes` with dotted names.
        $executions = array_values(array_filter((array) ($data['data'] ?? []), 'is_array'));

        if ($pipeline = $this->option('pipeline')) {
            $executions = array_values(array_filter(
                $executions,
                fn (array $execution) => ($execution['attributes']['pipeline.name'] ?? null) === $pipeline,
            ));
        }

        if ($this->option('json')) {
            $this->raw($redactor->redact(json_encode($executions, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE)));

            return self::SUCCESS;
        }

        if ($executions === []) {
            $this->components->warn("No executions in {$environment} in the last {$this->option('since')}.");

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

        $this->line('<fg=gray>Open one with: php artisan digibee:logs --environment=' . $environment . ' --key=<execution key></>');

        return self::SUCCESS;
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
            ? \Carbon\CarbonImmutable::parse($timestamp)->setTimezone((string) config('app.display_timezone'))->format('d/m H:i:s')
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
