<?php

namespace App\Support\Digibee\Monitor;

use Carbon\CarbonImmutable;

/**
 * The reads every surface offers over the Digibee logs — count, find, collect
 * and one execution — given a client already holding a session.
 *
 * Nothing here is redacted: each caller redacts at its own edge
 * (`LogRedactor`), the one place that knows where the text is going.
 */
final class LogSearch
{
    /** Executions opened per round when a search is bounded by time. */
    private const OPEN_CHUNK = 25;

    private readonly ExecutionCollector $collector;

    public function __construct(private readonly DigibeeMonitorClient $client)
    {
        $this->collector = new ExecutionCollector($client);
    }

    /**
     * How many executions (and log lines) match, without opening any.
     *
     * @return array<string, int|string|null> source => count, "10000+" when capped
     */
    public function count(LogQuery $query): array
    {
        $counts = [];

        foreach ($query->sources() as $source => $inLines) {
            $data = $inLines
                ? $this->client->pipelineLogs($query->environment, $query->from, $query->to, 1, $query->pipeline, $query->term)
                : $this->client->executions($query->environment, $query->from, $query->to, 1, $query->pipeline, $query->term);

            $counts[$source] = self::countOf($data['total'] ?? null);
        }

        return $counts;
    }

    /**
     * The executions the query matches, newest first, NOT opened. The same
     * execution found through both doors is one entry: payload fields from
     * one, matched lines from the other.
     *
     * @return array{coverage: array<string, array<string, mixed>>, found: array<string, array<string, mixed>>}
     */
    public function find(LogQuery $query, int $pages = ExecutionCollector::MAX_PAGES, int $limit = DigibeeMonitorClient::MAX_PAGE): array
    {
        $coverage = [];
        $found = [];

        foreach ($query->sources() as $source => $inLines) {
            $search = $this->collector->find($query->environment, $query->from, $query->to, $query->pipeline, $query->term, $inLines, $limit, $pages);

            $coverage[$source] = [
                'matches'        => $search['matches'],
                'platform_total' => self::countOf($search['total']),
                'more_in_window' => $search['has_next'],
                'executions'     => count($search['found']),
            ];

            foreach ($search['found'] as $key => $execution) {
                $found[$key] = isset($found[$key]) ? $found[$key] + $execution : $execution;
            }
        }

        return ['coverage' => $coverage, 'found' => $found];
    }

    /**
     * Finds, then opens every match up to `$max` — or until `$deadline`,
     * for a caller that must answer inside a request. Whatever was not opened
     * is named in `not_opened`, never silently dropped.
     *
     * @return array<string, mixed> the report: query, coverage, counts and the opened executions
     */
    public function collect(LogQuery $query, int $max, int $pages = ExecutionCollector::MAX_PAGES, ?CarbonImmutable $deadline = null): array
    {
        ['coverage' => $coverage, 'found' => $found] = $this->find($query, $pages);

        $opened = $deadline === null
            ? $this->collector->open($query->environment, $found, $max)
            : $this->openUntil($query->environment, $found, $max, $deadline);

        return [
            'query'            => $query->describe(),
            'coverage'         => $coverage,
            'executions_found' => count($found),
            'not_opened'       => $opened['not_opened'],
            'unreadable'       => $opened['unreadable'],
            'executions'       => $opened['executions'],
        ];
    }

    /**
     * Every log line of one execution, in order, or null when the platform
     * has none (a wrong key, or a log past its retention).
     *
     * @return array{key: string, pipeline: ?string, environment: string, lines: list<array<string, mixed>>}|null
     */
    public function execution(string $environment, string $key): ?array
    {
        $rows = array_reverse($this->client->executionLogs($environment, $key));

        if ($rows === []) {
            return null;
        }

        return [
            'key'         => $key,
            'pipeline'    => $rows[0]['pipelineName'] ?? null,
            'environment' => $environment,
            'lines'       => array_map(fn (array $row) => [
                'at'      => MonitorTime::iso($row['timestamp'] ?? null),
                'level'   => $row['logLevel'] ?? null,
                'message' => $row['logMessage'] ?? null,
            ], $rows),
        ];
    }

    public static function countOf(mixed $total): int|string|null
    {
        if (! is_int($total)) {
            return null;
        }

        return $total >= ExecutionCollector::CAPPED_TOTAL ? ExecutionCollector::CAPPED_TOTAL . '+' : $total;
    }

    /**
     * Opens in rounds of OPEN_CHUNK and stops starting rounds once the
     * deadline has passed.
     *
     * @param  array<string, array<string, mixed>>  $found
     * @return array{executions: list<array<string, mixed>>, not_opened: list<string>, unreadable: list<string>}
     */
    private function openUntil(string $environment, array $found, int $max, CarbonImmutable $deadline): array
    {
        $keys = array_slice(array_keys($found), 0, $max);
        $executions = [];
        $unreadable = [];
        $openedKeys = [];

        foreach (array_chunk($keys, self::OPEN_CHUNK) as $chunk) {
            if ($openedKeys !== [] && now()->isAfter($deadline)) {
                break;
            }

            $round = $this->collector->open($environment, array_intersect_key($found, array_flip($chunk)), count($chunk));
            array_push($executions, ...$round['executions']);
            array_push($unreadable, ...$round['unreadable']);
            array_push($openedKeys, ...$chunk);
        }

        usort($executions, fn (array $a, array $b) => strcmp((string) $a['started_at'], (string) $b['started_at']));

        return [
            'executions' => $executions,
            'not_opened' => array_values(array_diff(array_keys($found), $openedKeys)),
            'unreadable' => $unreadable,
        ];
    }
}
