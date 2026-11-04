<?php

namespace App\Support\Digibee\Monitor;

/**
 * What a chat can read of a collected report: the aggregates first, then a
 * bounded timeline.
 *
 * In a terminal the report file is queried with jq. A chat client has no
 * terminal, and a few hundred executions are megabytes, so the questions a
 * report is FOR — how many, which failed, with what, how slow, when — are
 * answered here, and the file stays on disk for `query_digibee_report` to
 * drill into.
 *
 * Not redacted: the MCP server redacts whatever it sends, here included.
 */
final class ReportDigest
{
    /** Executions spelled out in the timeline; the rest are a query away. */
    public const TIMELINE_MAX = 60;

    /** Distinct ERROR/WARN messages listed. */
    private const MESSAGES_MAX = 30;

    /** Distinct HTTP targets listed. */
    private const CALLS_MAX = 25;

    /** Notable lines per execution in the timeline. */
    private const NOTABLE_MAX = 6;

    /** Characters kept of any one line or payload in a digest. */
    private const EXCERPT = 300;

    /**
     * @param  list<array<string, mixed>>  $executions  as ExecutionCollector::open() returns them
     * @return array<string, mixed>
     */
    public static function of(array $executions, ?string $term = null): array
    {
        return [
            'by_pipeline'   => self::byPipeline($executions),
            'levels'        => self::levels($executions),
            'messages'      => self::messages($executions),
            'http_calls'    => self::calls($executions),
            'timeline'      => array_map(fn (array $execution) => self::compact($execution, $term), array_slice($executions, 0, self::TIMELINE_MAX)),
            'timeline_note' => count($executions) > self::TIMELINE_MAX
                ? 'A linha do tempo mostra as primeiras ' . self::TIMELINE_MAX . ' de ' . count($executions) . ' execuções (ordem cronológica); use query_digibee_report para as demais.'
                : null,
        ];
    }

    /**
     * One execution in a few lines: what it was, how it ended, and the lines
     * that tell its story — errors, warnings, the HTTP answers, and whatever
     * mentioned the term.
     *
     * @param  array<string, mixed>  $execution
     * @return array<string, mixed>
     */
    public static function compact(array $execution, ?string $term = null): array
    {
        $notable = array_values(array_filter(
            $execution['lines'] ?? [],
            fn (array $line) => in_array(strtoupper((string) ($line['level'] ?? '')), ['ERROR', 'FATAL', 'WARN', 'WARNING'], true)
                || str_contains((string) ($line['message'] ?? ''), 'responded with status code')
                || ($term !== null && $term !== '' && str_contains((string) ($line['message'] ?? ''), $term)),
        ));

        return array_filter([
            'at'            => $execution['started_at'] ?? null,
            'key'           => $execution['key'] ?? null,
            'pipeline'      => trim(($execution['pipeline'] ?? '?') . ' ' . ($execution['version'] ?? '')),
            'status'        => $execution['status'] ?? null,
            'elapsed_ms'    => $execution['elapsed_ms'] ?? null,
            'levels'        => $execution['levels'] ?? null,
            'unreadable'    => ($execution['unreadable'] ?? false) ?: null,
            'request'       => self::excerpt($execution['payload_request'] ?? null),
            'notable_lines' => array_map(
                fn (array $line) => substr((string) ($line['at'] ?? ''), 11, 12) . ' ' . ($line['level'] ?? '') . ' ' . self::excerpt($line['message'] ?? null),
                array_slice($notable, 0, self::NOTABLE_MAX),
            ),
            'more_notable' => count($notable) > self::NOTABLE_MAX ? count($notable) - self::NOTABLE_MAX : null,
        ], fn ($value) => $value !== null && $value !== []);
    }

    /** @param  list<array<string, mixed>>  $executions */
    private static function byPipeline(array $executions): array
    {
        $groups = [];

        foreach ($executions as $execution) {
            $groups[$execution['pipeline'] ?? '?'][] = $execution;
        }

        return array_map(function (string $pipeline, array $group) {
            $elapsed = array_values(array_filter(array_column($group, 'elapsed_ms'), 'is_numeric'));
            $statuses = array_count_values(array_map('strval', array_filter(array_column($group, 'status'), fn ($s) => $s !== null)));
            ksort($statuses);

            return array_filter([
                'pipeline'     => $pipeline,
                'versions'     => array_values(array_unique(array_filter(array_column($group, 'version')))),
                'executions'   => count($group),
                'with_error'   => count(array_filter($group, fn (array $e) => ($e['levels']['ERROR'] ?? 0) + ($e['levels']['FATAL'] ?? 0) > 0)),
                'with_warning' => count(array_filter($group, fn (array $e) => ($e['levels']['WARN'] ?? 0) + ($e['levels']['WARNING'] ?? 0) > 0)),
                'unreadable'   => count(array_filter($group, fn (array $e) => $e['unreadable'] ?? false)),
                'statuses'     => $statuses ?: null,
                'elapsed_ms'   => self::spread($elapsed),
                'first'        => $group[0]['started_at'] ?? null,
                'last'         => $group[count($group) - 1]['started_at'] ?? null,
            ], fn ($value) => $value !== null);
        }, array_keys($groups), $groups);
    }

    /** @param  list<array<string, mixed>>  $executions */
    private static function levels(array $executions): array
    {
        $levels = [];

        foreach ($executions as $execution) {
            foreach ($execution['levels'] ?? [] as $level => $count) {
                $levels[$level] = ($levels[$level] ?? 0) + $count;
            }
        }

        return $levels;
    }

    /**
     * ERROR and WARN lines grouped by SHAPE — ids and numbers stripped — so a
     * thousand "timeout on order 123…" read as one message with its count.
     *
     * @param  list<array<string, mixed>>  $executions
     */
    private static function messages(array $executions): array
    {
        $groups = [];

        foreach ($executions as $execution) {
            foreach ($execution['lines'] ?? [] as $line) {
                $level = strtoupper((string) ($line['level'] ?? ''));

                if (! in_array($level, ['ERROR', 'FATAL', 'WARN', 'WARNING'], true)) {
                    continue;
                }

                $message = (string) ($line['message'] ?? '');
                $id = $level . ' ' . self::shape($message);

                $groups[$id] ??= ['level' => $level, 'shape' => self::shape($message), 'count' => 0, 'executions' => [], 'first' => $line['at'] ?? null, 'example_key' => $execution['key'] ?? null, 'example' => self::excerpt($message)];
                $groups[$id]['count']++;
                $groups[$id]['executions'][$execution['key'] ?? ''] = true;
                $groups[$id]['last'] = $line['at'] ?? null;
            }
        }

        usort($groups, fn (array $a, array $b) => $b['count'] <=> $a['count']);

        return [
            'distinct' => count($groups),
            'top'      => array_map(function (array $group) {
                $group['executions'] = count($group['executions']);

                return $group;
            }, array_slice($groups, 0, self::MESSAGES_MAX)),
        ];
    }

    /**
     * Every "URL … responded with status code N. Took: T" line, grouped by
     * target and status — the connector line Digibee writes for each HTTP call.
     *
     * @param  list<array<string, mixed>>  $executions
     */
    private static function calls(array $executions): array
    {
        $groups = [];

        foreach ($executions as $execution) {
            foreach ($execution['lines'] ?? [] as $line) {
                if (preg_match('/URL (\S+) responded with status code (\d+)\. Took: (\d+)/', (string) ($line['message'] ?? ''), $m) !== 1) {
                    continue;
                }

                $target = self::endpoint($m[1]);
                $id = $target . ' ' . $m[2];

                $groups[$id] ??= ['target' => $target, 'status' => (int) $m[2], 'count' => 0, 'took' => []];
                $groups[$id]['count']++;
                $groups[$id]['took'][] = (int) $m[3];
            }
        }

        usort($groups, fn (array $a, array $b) => $b['count'] <=> $a['count']);

        return array_map(function (array $group) {
            $group['took_ms'] = self::spread($group['took']);
            unset($group['took']);

            return $group;
        }, array_slice($groups, 0, self::CALLS_MAX));
    }

    /** @param  list<int|float>  $values */
    private static function spread(array $values): ?array
    {
        if ($values === []) {
            return null;
        }

        sort($values);
        $at = fn (float $q) => $values[(int) floor($q * (count($values) - 1))];

        return ['min' => $values[0], 'median' => $at(0.5), 'p95' => $at(0.95), 'max' => $values[count($values) - 1]];
    }

    /**
     * A URL as an endpoint: no query string, and an id-like path segment (one
     * with a digit: a SKU, a warehouse, a uuid) as `{id}`. Only the PATH — the
     * message rule turned `leob2c…:443` into `leob#c…:#`, which no one can
     * recognise as their host.
     */
    private static function endpoint(string $url): string
    {
        $url = (string) preg_replace('/[?#].*$/', '', $url);

        if (preg_match('#^([a-z]+://[^/]+)(/.*)?$#i', $url, $m) !== 1) {
            return $url;
        }

        $path = implode('/', array_map(
            fn (string $segment) => preg_match('/\d/', $segment) === 1 ? '{id}' : $segment,
            explode('/', $m[2] ?? ''),
        ));

        return $m[1] . $path;
    }

    /** A message with what varies per execution taken out. */
    private static function shape(string $message): string
    {
        $message = preg_replace('/[0-9a-f]{8}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{12}/i', '<id>', $message);
        $message = preg_replace('/\d+/', '#', (string) $message);

        return mb_substr(trim((string) $message), 0, 200);
    }

    private static function excerpt(mixed $text): ?string
    {
        if (! is_string($text) || $text === '') {
            return null;
        }

        return mb_strlen($text) > self::EXCERPT ? mb_substr($text, 0, self::EXCERPT) . '…' : $text;
    }
}
