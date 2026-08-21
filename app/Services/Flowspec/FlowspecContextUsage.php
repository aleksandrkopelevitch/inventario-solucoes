<?php

namespace App\Services\Flowspec;

/**
 * What one conversation's next request is estimated to cost, broken down the
 * way the composer's meter shows it.
 *
 * Two ceilings, not one, and the difference matters:
 *
 * - `limit` is the whole request's budget (`context_limit_tokens`).
 * - `attachLimit` is `limit` minus the history reserve — the point past which
 *   ATTACHING is refused. History is never refused, it trims itself, because
 *   locking someone out of their own conversation is a worse outcome than
 *   dropping its oldest turns.
 */
final class FlowspecContextUsage
{
    /** @param array<string, int> $lines label => estimated tokens, in display order */
    public function __construct(
        public readonly array $lines,
        public readonly int $attached,
        public readonly int $history,
        public readonly int $fixed,
        public readonly int $limit,
        public readonly int $attachLimit,
    ) {}

    public function total(): int
    {
        return $this->fixed + $this->attached + $this->history;
    }

    /** Room left for anything new to be attached. Never negative. */
    public function attachableTokens(): int
    {
        return max(0, $this->attachLimit - $this->fixed - $this->attached);
    }

    /** Tokens the history may use on this turn before it has to trim itself. */
    public function historyAllowance(): int
    {
        return max(0, $this->limit - $this->fixed - $this->attached);
    }

    /** Nothing more fits — the composer refuses new attachments and says why. */
    public function attachmentsFull(): bool
    {
        return $this->attachableTokens() <= 0;
    }

    /**
     * Percentage for the meter, capped at 100: the bar is a bar, and a 140%
     * fill just renders as an overflowing div.
     */
    public function percent(): int
    {
        return $this->limit <= 0 ? 0 : min(100, (int) round($this->total() / $this->limit * 100));
    }

    /** Past this the meter turns warning-colored — the same 80% Claude nudges at. */
    public function nearLimit(): bool
    {
        return $this->percent() >= 80;
    }

    /** @return array<string, int> the breakdown, dropping lines that cost nothing */
    public function visibleLines(): array
    {
        return array_filter($this->lines, fn (int $tokens) => $tokens > 0);
    }
}
