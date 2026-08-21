<?php

namespace App\Services\Flowspec;

/**
 * A built user prompt plus what had to be left out of it.
 *
 * `trimmedHistoryTurns` exists because the history is the one part of a request
 * that grows on its own: attachments are refused when they wouldn't fit, but a
 * long conversation can't be refused without locking someone out of their own
 * chat, so its oldest turns are dropped instead. That has to be VISIBLE — a
 * chat that quietly forgot its beginning would read as the model losing track.
 */
final class FlowspecPrompt
{
    public function __construct(
        public readonly string $text,
        public readonly int $trimmedHistoryTurns = 0,
    ) {}
}
