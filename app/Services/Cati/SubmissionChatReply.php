<?php

namespace App\Services\Cati;

/**
 * One turn of the interview: the conversational text (draft blocks already
 * stripped), the drafts it proposed keyed by section, and auditable metadata.
 *
 * `drafts` is a list rather than a single value because one answer often
 * fills two sections at once ("isso responde o Resumo e metade dos Objetivos").
 */
final class SubmissionChatReply
{
    /**
     * @param  list<array{key: string, markdown: string}>  $drafts
     * @param  array<string, mixed>  $meta
     */
    public function __construct(
        public readonly string $content,
        public readonly array $drafts,
        public readonly array $meta,
    ) {}
}
