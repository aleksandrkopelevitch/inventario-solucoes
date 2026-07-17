<?php

namespace App\Services\Documentation;

/**
 * Result of an "Assiste IA" generation: the generated Markdown (already
 * stripped of accidental code fences) and auditable metadata (provider/model/
 * tokens, attached/inlined documents).
 */
final class DocumentationDraftResult
{
    /** @param  array<string, mixed>  $meta */
    public function __construct(
        public readonly string $markdown,
        public readonly array $meta,
    ) {}
}
