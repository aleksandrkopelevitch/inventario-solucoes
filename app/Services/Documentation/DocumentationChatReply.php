<?php

namespace App\Services\Documentation;

/**
 * Result of one Documentation Assistant turn: the conversational reply text
 * (draft block already stripped), the extracted draft Markdown when the reply
 * proposed one (null for a purely conversational turn), and auditable
 * metadata (provider/model/tokens, attached/inlined/omitted documents,
 * requirements snapshot).
 */
final class DocumentationChatReply
{
    /** @param  array<string, mixed>  $meta */
    public function __construct(
        public readonly string $content,
        public readonly ?string $draft,
        public readonly array $meta,
    ) {}
}
