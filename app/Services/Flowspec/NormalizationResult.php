<?php

namespace App\Services\Flowspec;

/**
 * Output of DigibeeFlowspecNormalizer: the corrected document and the trail
 * of applied fixes (audited in `flowspec_messages.meta`).
 */
final class NormalizationResult
{
    /**
     * @param  array<string, mixed>  $document
     * @param  list<string>  $fixes
     */
    public function __construct(
        public readonly array $document,
        public readonly array $fixes,
    ) {}
}
