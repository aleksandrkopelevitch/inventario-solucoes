<?php

namespace App\Services\Flowspec;

/**
 * Saída do DigibeeFlowspecNormalizer: o documento corrigido e o rastro das
 * correções aplicadas (auditado em `flowspec_messages.meta`).
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
