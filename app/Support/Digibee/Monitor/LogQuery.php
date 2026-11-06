<?php

namespace App\Support\Digibee\Monitor;

use Carbon\CarbonImmutable;

/**
 * One question asked of the Digibee logs — environment, window, pipeline,
 * term and where to look for it — fixed once, so every page, every source
 * and the report that comes out of it agree on the same two instants.
 *
 * The local MCP server, the iSol `/mcp` tools and the "Logs Digibee" screen
 * all build one of these and hand it to `LogSearch`.
 */
final class LogQuery
{
    public const ENVIRONMENTS = ['prod', 'test'];

    public const WHERE = ['both', 'lines', 'payloads'];

    public function __construct(
        public readonly string $environment,
        public readonly CarbonImmutable $from,
        public readonly CarbonImmutable $to,
        public readonly ?string $pipeline = null,
        public readonly ?string $term = null,
        public readonly string $where = 'both',
    ) {
        if (! in_array($environment, self::ENVIRONMENTS, true) || ! in_array($where, self::WHERE, true)) {
            throw new \InvalidArgumentException("Unknown environment \"{$environment}\" or where \"{$where}\".");
        }
    }

    /**
     * From what a person or a model typed; the window goes through
     * `MonitorTime::window()`, which refuses what it cannot read.
     */
    public static function make(string $environment, ?string $since, ?string $until, ?string $pipeline, ?string $term, ?string $where): self
    {
        [$from, $to] = MonitorTime::window($since ?? '1d', $until);

        return new self($environment, $from, $to, self::blank($pipeline), self::blank($term), $where ?? 'both');
    }

    /** Neither a pipeline nor a term is the whole realm — thousands of executions a minute. */
    public function narrowed(): bool
    {
        return $this->pipeline !== null || $this->term !== null;
    }

    /**
     * Which routes to ask: with a term, where it may appear; without one, the
     * executions of the window.
     *
     * @return array<string, bool> source name => searches log lines
     */
    public function sources(): array
    {
        if ($this->term === null) {
            return ['executions' => false];
        }

        return match ($this->where) {
            'lines'    => ['lines' => true],
            'payloads' => ['payloads' => false],
            default    => ['lines' => true, 'payloads' => false],
        };
    }

    /** @return array<string, string> */
    public function window(): array
    {
        return ['from' => MonitorTime::display($this->from), 'to' => MonitorTime::display($this->to), 'timezone' => MonitorTime::timezone()];
    }

    /** The `query` section of a report. @return array<string, string> */
    public function describe(): array
    {
        return array_filter([
            'environment' => $this->environment,
            'searched'    => implode(' + ', array_keys($this->sources())),
            'pipeline'    => $this->pipeline,
            'term'        => $this->term,
            'from'        => MonitorTime::iso($this->from->toIso8601String()),
            'to'          => MonitorTime::iso($this->to->toIso8601String()),
            'timezone'    => MonitorTime::timezone(),
        ]);
    }

    private static function blank(?string $value): ?string
    {
        $value = trim((string) $value);

        return $value === '' ? null : $value;
    }
}
