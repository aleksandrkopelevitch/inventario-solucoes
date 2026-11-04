<?php

namespace App\Support\Digibee\Monitor;

use Carbon\CarbonImmutable;
use Carbon\Exceptions\InvalidFormatException;

/**
 * Time as the person reading the Monitor writes and reads it: on the local
 * clock (`app.display_timezone`), while the platform speaks UTC.
 *
 * Shared by `digibee:logs` and the local MCP server, so a window typed in a
 * terminal and one typed into a chat mean the same two instants.
 */
final class MonitorTime
{
    /** Every date/time shape `instant()` reads, tried in order. */
    private const FORMATS = [
        'Y-m-d H:i:s', 'Y-m-d H:i', 'Y-m-d',
        'd/m/Y H:i', 'd/m/Y', 'd/m H:i', 'd/m',
        'j/n/Y H:i', 'j/n/Y G:i', 'j/n/Y', 'j/n H:i', 'j/n G:i', 'j/n',
        'H:i', 'G:i',
    ];

    /**
     * One end of a window: how long ago (`15m`, `6h`, `2d`, `0m` = now) or a
     * date/time on the local clock. Anything else is refused — a typo used to
     * fall back to the last hour, and answer the wrong question without
     * saying so.
     */
    public static function instant(string $value, CarbonImmutable $now): CarbonImmutable
    {
        $value = trim($value);

        if (preg_match('/^(\d+)\s*([mhd])$/', $value, $m) === 1) {
            return match ($m[2]) {
                'm' => $now->subMinutes((int) $m[1]),
                'h' => $now->subHours((int) $m[1]),
                'd' => $now->subDays((int) $m[1]),
            };
        }

        $timezone = self::timezone();
        $local = $now->setTimezone($timezone);

        // `!` zeroes what the format leaves out, so "03/11" is midnight.
        foreach (self::FORMATS as $format) {
            try {
                $parsed = CarbonImmutable::createFromFormat('!' . $format, $value, $timezone);
            } catch (InvalidFormatException) {
                continue;
            }

            // Round trip: PHP happily rolls "31/02" over into March.
            if ($parsed === null || $parsed->format($format) !== $value) {
                continue;
            }

            // A format without the year (or the date) means this one (today).
            $hasDate = preg_match('/[dj]/', $format) === 1;

            if (! str_contains($format, 'Y')) {
                $parsed = $hasDate
                    ? $parsed->setDate($local->year, $parsed->month, $parsed->day)
                    : $parsed->setDate($local->year, $local->month, $local->day);

                // "31/12" read on 2 January is last year's: logs never come
                // from the future.
                if ($hasDate && $parsed->gt($local->addDay())) {
                    $parsed = $parsed->subYear();
                }
            }

            return $parsed->utc();
        }

        throw new \InvalidArgumentException(
            "Cannot read \"{$value}\" as a time — use how long ago (15m, 6h, 2d) or a local date/time "
            . '("2026-11-03 14:00", "03/11/2026 14:00", "03/11 14:00", "14:00").'
        );
    }

    /**
     * Both ends, read against ONE "now", refused when they are reversed.
     *
     * @return array{0: CarbonImmutable, 1: CarbonImmutable}
     */
    public static function window(string $since, ?string $until, ?CarbonImmutable $now = null): array
    {
        $now ??= CarbonImmutable::now();
        $to = self::instant(($until === null || trim($until) === '') ? '0m' : $until, $now);
        $from = self::instant($since, $now);

        if ($from >= $to) {
            throw new \InvalidArgumentException('The start (' . self::display($from) . ') must be before the end (' . self::display($to) . ').');
        }

        return [$from, $to];
    }

    /** For a sentence: `03/11/2026 14:00`. */
    public static function display(CarbonImmutable $instant): string
    {
        return $instant->setTimezone(self::timezone())->format('d/m/Y H:i');
    }

    /** For a terminal line: `03/11 14:00:07`. */
    public static function short(mixed $timestamp): string
    {
        return is_string($timestamp) && $timestamp !== ''
            ? CarbonImmutable::parse($timestamp)->setTimezone(self::timezone())->format('d/m H:i:s')
            : '';
    }

    /** For a file: sortable, millisecond precision — `d/m` does not sort across months. */
    public static function iso(mixed $timestamp): ?string
    {
        return is_string($timestamp) && $timestamp !== ''
            ? CarbonImmutable::parse($timestamp)->setTimezone(self::timezone())->format('Y-m-d H:i:s.v')
            : null;
    }

    public static function timezone(): string
    {
        return (string) config('app.display_timezone');
    }
}
