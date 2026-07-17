<?php

namespace App\Services\Flowspec;

/**
 * Result of DigibeeFlowspecValidator: a list of concrete errors, ready to
 * become a re-prompt in FlowspecGenerationService's correction loop.
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
