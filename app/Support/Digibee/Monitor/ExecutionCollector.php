<?php

namespace App\Support\Digibee\Monitor;

use Carbon\CarbonImmutable;

/**
 * Finds the executions a search matches, then opens every one of them — the
 * work behind `digibee:logs --collect` and the local MCP server's search.
 *
 * Two steps on purpose. A search over log lines and one over payloads find
 * the same execution through different doors; finding both first and opening
 * the union once is what keeps "search both" from exporting every execution
 * twice.
 *
 * What comes back is NOT redacted. Every caller redacts at its own edge
 * (`LogRedactor::json()`), the one place that knows where the text is going.
 */
final class ExecutionCollector
{
    /** 20 pages of 100 — past that, a narrower window or filter is the better question. */
    public const MAX_PAGES = 20;

    /** Both routes answer a `total` of exactly this for anything bigger (measured 2026-11-04). */
    public const CAPPED_TOTAL = 10000;

    public function __construct(private readonly DigibeeMonitorClient $client) {}

    /**
     * One search: log lines by text (`$inLines`), executions by payload text,
     * or — no term — every execution of the window.
     *
     * @return array{matches: int, total: ?int, has_next: bool, found: array<string, array<string, mixed>>}
     */
    public function find(
        string $environment,
        CarbonImmutable $from,
        CarbonImmutable $to,
        ?string $pipeline,
        ?string $term,
        bool $inLines,
        int $limit = DigibeeMonitorClient::MAX_PAGE,
        int $pages = self::MAX_PAGES,
    ): array {
        [$records, $total, $hasNext] = self::paged(
            fn (?string $cursor) => $inLines
                ? $this->client->pipelineLogs($environment, $from, $to, $limit, $pipeline, $term, $cursor)
                : $this->client->executions($environment, $from, $to, $limit, $pipeline, $term, $cursor),
            $pages,
        );

        // One entry per execution, in the order the search found them (newest
        // first); a log-line search finds the same execution once per line.
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
            ] + ($inLines ? ['matched_lines' => []] : [
                'status'           => self::statusCode($attributes),
                'elapsed_ms'       => $attributes['execution.elapsed_time_ms'] ?? null,
                'payload_request'  => $attributes['payload.request'] ?? null,
                'payload_response' => $attributes['payload.response'] ?? null,
            ]);

            if ($inLines) {
                $found[$key]['matched_lines'][] = (string) ($record['body'] ?? '');
            }
        }

        return ['matches' => count($records), 'total' => $total, 'has_next' => $hasNext, 'found' => $found];
    }

    /**
     * Opens the first `$max` executions of `$found` and reads each top to
     * bottom; the rest are named, never silently dropped.
     *
     * @param  array<string, array<string, mixed>>  $found
     * @return array{executions: list<array<string, mixed>>, not_opened: list<string>, unreadable: list<string>}
     */
    public function open(string $environment, array $found, int $max): array
    {
        $keys = array_slice(array_keys($found), 0, $max);
        $logs = $keys === [] ? [] : $this->client->executionLogsMany($environment, $keys);

        $executions = [];

        foreach ($keys as $key) {
            // The export comes newest first; an execution reads top to bottom.
            $rows = $logs[$key] === null ? null : array_reverse($logs[$key]);

            $executions[] = $found[$key] + [
                'started_at' => $rows ? MonitorTime::iso($rows[0]['timestamp'] ?? null) : null,
                'ended_at'   => $rows ? MonitorTime::iso($rows[count($rows) - 1]['timestamp'] ?? null) : null,
                'levels'     => $rows === null ? [] : array_count_values(array_map(fn (array $row) => strtoupper($row['logLevel'] ?? '?'), $rows)),
                'unreadable' => $rows === null,
                'lines'      => array_map(fn (array $row) => [
                    'at'      => MonitorTime::iso($row['timestamp'] ?? null),
                    'level'   => $row['logLevel'] ?? null,
                    'message' => $row['logMessage'] ?? null,
                ], $rows ?? []),
            ];
        }

        // Chronological: a report reads as what happened, in order.
        usort($executions, fn (array $a, array $b) => strcmp((string) $a['started_at'], (string) $b['started_at']));

        return [
            'executions' => $executions,
            'not_opened' => array_values(array_slice(array_keys($found), $max)),
            'unreadable' => array_values(array_keys(array_filter($logs, fn ($rows) => $rows === null))),
        ];
    }

    /**
     * Follows the next-page cursor up to `$pages` times.
     *
     * The caller fixes the window before the first page: a cursor belongs to
     * the query that issued it, and a `to` that moved on every page would be
     * a different query.
     *
     * @param  callable(?string): array<mixed>  $fetch
     * @return array{0: list<array<mixed>>, 1: ?int, 2: bool}
     */
    public static function paged(callable $fetch, int $pages): array
    {
        $pages = max(1, min($pages, self::MAX_PAGES));

        $records = [];
        $cursor = null;
        $total = null;
        $hasNext = false;

        for ($page = 0; $page < $pages; $page++) {
            $data = $fetch($cursor);

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

    /** The response code the pipeline answered, read from the captured response payload. */
    public static function statusCode(array $attributes): ?int
    {
        $response = json_decode((string) ($attributes['payload.response'] ?? ''), true);
        $code = is_array($response) ? ($response['code'] ?? null) : null;

        return is_numeric($code) ? (int) $code : null;
    }
}
