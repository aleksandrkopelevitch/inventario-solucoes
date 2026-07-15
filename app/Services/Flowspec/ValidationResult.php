<?php

namespace App\Services\Flowspec;

/**
 * Resultado do DigibeeFlowspecValidator: lista de erros concretos, prontos
 * para virar re-prompt no loop de correção do FlowspecGenerationService.
 */
final class ValidationResult
{
    /** @param list<string> $errors */
    public function __construct(public readonly array $errors) {}

    public function passes(): bool
    {
        return $this->errors === [];
    }
}
