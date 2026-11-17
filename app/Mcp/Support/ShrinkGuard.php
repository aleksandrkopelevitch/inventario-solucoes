<?php

namespace App\Mcp\Support;

/**
 * When a rewritten page body has "shrunk" enough to refuse it without an
 * explicit confirmation — the guard `update_page` holds against a model that
 * replaces half a page with "[restante inalterado]".
 *
 * ONE place for the rule, because two things read it: the tool that enforces
 * it and `mcp:calibrate-shrink-guard`, which replays it over real rewrites to
 * say whether these numbers refuse legitimate ones. A calibration that
 * re-implemented the arithmetic would be measuring a guard nobody runs.
 *
 * Both thresholds are starting values (docs-and-specs/mcp-write-tools.md §14):
 * change them only with the calibration's output in hand.
 */
final class ShrinkGuard
{
    /** Below this many characters a page is too short for "it shrank" to mean anything. */
    public const MIN_CHARS = 2000;

    /** A body keeping less than this share of the old one has "shrunk". */
    public const RATIO = 0.6;

    /**
     * The share of the old body the new one keeps, as a band rounded DOWN to a
     * multiple of 10 (what a confirmation repeats: `shrink:30%`), or null when
     * the guard does not fire at all.
     */
    public static function band(int $oldChars, int $newChars, float $ratio = self::RATIO, int $minChars = self::MIN_CHARS): ?int
    {
        if ($oldChars <= $minChars || $newChars >= $oldChars * $ratio) {
            return null;
        }

        return (int) (floor($newChars / $oldChars * 10) * 10);
    }
}
