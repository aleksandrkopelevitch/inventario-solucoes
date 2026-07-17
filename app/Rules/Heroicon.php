<?php

namespace App\Rules;

use App\Support\Heroicons;
use Closure;
use Illuminate\Contracts\Validation\ValidationRule;

/** Validates that the value is the slug (kebab-case) of an existing outline icon in the heroicons set. */
class Heroicon implements ValidationRule
{
    public function validate(string $attribute, mixed $value, Closure $fail): void
    {
        if (! is_string($value) || ! preg_match('/^[a-z0-9]+(-[a-z0-9]+)*$/', $value)) {
            $fail('O ícone informado é inválido.');

            return;
        }

        if (! Heroicons::exists($value)) {
            $fail('O ícone informado não existe.');
        }
    }
}
