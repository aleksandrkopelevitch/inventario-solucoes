<?php

namespace App\View\Components\Digibee;

use App\Support\Digibee\Monitor\LogQuery;
use App\Support\Digibee\Monitor\LogRedactor;
use App\View\Components\Concerns\Renderable;
use Illuminate\Contracts\View\View;
use Illuminate\View\Component;

/**
 * What "Buscar" found on the "Logs Digibee" screen: the matches, NOT opened,
 * newest first, each one a click away from its whole log. Everything printed
 * from a payload or a log line is redacted here, at the edge.
 */
class LogResults extends Component
{
    use Renderable;

    public const DOM_ID = 'digibee-log-results';

    /** Characters of a matched line or payload shown in a row. */
    private const EXCERPT = 220;

    /**
     * @param  array<string, array<string, mixed>>  $coverage
     * @param  array<string, array<string, mixed>>  $found
     */
    public function __construct(
        public readonly ?LogQuery $query = null,
        public readonly array $coverage = [],
        public readonly array $found = [],
    ) {}

    /**
     * @param  array<string, array<string, mixed>>  $coverage
     * @param  array<string, array<string, mixed>>  $found
     */
    public static function slot(LogQuery $query, array $coverage, array $found): array
    {
        return (new static($query, $coverage, $found))->toSlot(self::DOM_ID);
    }

    public function render(): View
    {
        $redactor = app(LogRedactor::class);
        $rows = array_values($this->found);
        usort($rows, fn (array $a, array $b) => strcmp((string) ($b['at'] ?? ''), (string) ($a['at'] ?? '')));

        return view('components.digibee.log-results', [
            'domId'    => self::DOM_ID,
            'query'    => $this->query,
            'coverage' => $this->coverage,
            'rows'     => array_map(fn (array $row) => [
                'key'      => $row['key'],
                'at'       => self::clock($row['at'] ?? null),
                'pipeline' => trim(($row['pipeline'] ?? '?') . ' ' . ($row['version'] ?? '')),
                'status'   => $row['status'] ?? null,
                'elapsed'  => self::duration($row['elapsed_ms'] ?? null),
                'excerpt'  => $this->excerpt($redactor, $row['matched_lines'][0] ?? $row['payload_request'] ?? null),
            ], $rows),
        ]);
    }

    /** Milliseconds below a second — most executions take a few — seconds above. */
    public static function duration(mixed $ms): ?string
    {
        if (! is_numeric($ms)) {
            return null;
        }

        return $ms < 1000 ? (int) $ms . ' ms' : number_format($ms / 1000, 1, ',', '.') . ' s';
    }

    /** `at` is already local (`MonitorTime::iso`): reformatted, never re-zoned. */
    public static function clock(mixed $at): ?string
    {
        return is_string($at) && strlen($at) >= 19 ? substr($at, 8, 2) . '/' . substr($at, 5, 2) . ' ' . substr($at, 11, 8) : null;
    }

    private function excerpt(LogRedactor $redactor, mixed $text): ?string
    {
        if (! is_string($text) || trim($text) === '') {
            return null;
        }

        $text = $redactor->redact($text);

        return mb_strlen($text) > self::EXCERPT ? mb_substr($text, 0, self::EXCERPT) . '…' : $text;
    }
}
