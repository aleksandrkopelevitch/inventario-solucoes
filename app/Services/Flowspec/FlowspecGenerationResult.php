<?php

namespace App\Services\Flowspec;

/**
 * Output of FlowspecGenerationService::generate(): the validated flowSpec (or
 * the best attempt, when the loop runs out), the raw text of the model's last
 * response (used as the assistant's message when no JSON came back), and the
 * auditable trail — context used, attempts with errors, fixes and tokens —
 * that gets recorded in `flowspec_messages.meta`.
 *
 * `credentialLeak` means the final best-attempt document still carried a
 * literal secret (CredentialScrubber flagged it) after every attempt: the
 * service withholds `document` (nulls it) in that case, and the raw `text`
 * must NOT be shown either, since it embeds the same secret.
 */
final class FlowspecGenerationResult
{
    /**
     * @param  array<string, mixed>|null  $document
     * @param  array<string, mixed>  $meta
     */
    public function __construct(
        public readonly ?array $document,
        public readonly string $text,
        public readonly bool $validated,
        public readonly array $meta,
        public readonly bool $credentialLeak = false,
    ) {}
}
