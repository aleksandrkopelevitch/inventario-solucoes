<?php

namespace App\Rules;

use Closure;
use Illuminate\Contracts\Validation\ValidationRule;

/**
 * Validates that a string parses as JSON. Used by the flowSpec composer's
 * optional reference field so a malformed paste fails with a friendly Toast
 * at validation time instead of blowing up later in the normalizer.
 */
class ValidJson implements ValidationRule
{
    public function validate(string $attribute, mixed $value, Closure $fail): void
    {
        if (! is_string($value)) {
            $fail('O flowSpec de referência deve ser um texto JSON.');

            return;
        }

        json_decode($value);

        if (json_last_error() !== JSON_ERROR_NONE) {
            $fail('O flowSpec de referência não é um JSON válido: ' . json_last_error_msg() . '.');
        }
    }
}
