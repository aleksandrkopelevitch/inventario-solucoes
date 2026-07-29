<?php

namespace App\Services\Documentation;

use Illuminate\Support\Collection;

/**
 * Result of `ContextDocumentResolver::resolve()`: context documents split
 * into text (inlined into the prompt) vs native attachments (PDF/image), plus
 * what got left out and why — surfaced in a chat message's `meta` for audit.
 */
final class ContextDocumentSet
{
    /**
     * @param  Collection<int, array{name: string, content: string}>  $textDocs
     * @param  list<object>  $attachments
     * @param  list<array{id: int, name: string, kind: string}>  $attachedMeta
     * @param  list<string>  $omittedAttachments
     * @param  list<string>  $omittedTexts
     * @param  list<string>  $omittedContext
     */
    public function __construct(
        public readonly Collection $textDocs,
        public readonly array $attachments,
        public readonly array $attachedMeta,
        public readonly array $omittedAttachments,
        public readonly array $omittedTexts,
        public readonly array $omittedContext,
    ) {}
}
