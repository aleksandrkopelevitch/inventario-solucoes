<?php

namespace App\Support\Context;

use App\Enums\ContextExtractionState;

/**
 * What reading one source produced. `Skipped` is a normal outcome, not a
 * failure — see the note on ContextExtractionState.
 */
final class ExtractedText
{
    public function __construct(
        public readonly ContextExtractionState $state,
        public readonly ?string $text,
        public readonly ?string $note,
    ) {}

    public static function done(string $text, ?string $note = null): self
    {
        return new self(ContextExtractionState::Done, $text, $note);
    }

    /** The file is useful, just not as inlined text: it rides along as a native attachment. */
    public static function skipped(string $note): self
    {
        return new self(ContextExtractionState::Skipped, null, $note);
    }

    public static function failed(string $note): self
    {
        return new self(ContextExtractionState::Failed, null, $note);
    }
}
